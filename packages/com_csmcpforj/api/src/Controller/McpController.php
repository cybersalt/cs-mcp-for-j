<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Api\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\Server;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use JsonException;

/**
 * Streamable-HTTP MCP endpoint. Single POST route at /api/index.php/v1/mcp.
 *
 * Authentication is handled by Joomla's standard API token auth plugin
 * (X-Joomla-Token header). Translation of Authorization: Bearer to
 * X-Joomla-Token happens earlier in plg_system_csmcpforj on onAfterInitialise.
 *
 * Output isolation: the entire request handler runs inside an output buffer
 * that is discarded before we write the JSON-RPC response. Without this guard
 * any stray PHP notice/warning emitted by core or by a tool — even one as
 * harmless as a deprecation — corrupts the JSON-RPC envelope and makes MCP
 * clients throw "Parse error: Unexpected token" on the first response. The
 * v1.5.0 FetchRenderedUrlTool emitted "Array to string conversion" on its
 * Content-Type header line and surfaced this bug; the buffer is the
 * structural fix that defends every tool, present and future.
 */
final class McpController extends BaseController
{
	public function handle(): void
	{
		// Output isolation. Anything echoed inside this handler — PHP
		// notices, warnings, debug echoes, third-party plugin chatter —
		// gets swallowed at the bottom so it can't precede or follow the
		// JSON-RPC payload on the wire.
		ob_start();

		try {
			$this->doHandle();
		} finally {
			// Discard whatever non-JSON output crept into the buffer
			// during request processing. The actual JSON response was
			// emitted via $app->close() inside sendJson() before we
			// reach this finally block, so the buffer contains only
			// noise — never the response itself.
			if (ob_get_level() > 0) {
				ob_end_clean();
			}
		}
	}

	private function doHandle(): void
	{
		/** @var CMSApplication $app */
		$app = $this->app ?? Factory::getApplication();

		if (strtoupper((string) $app->input->server->get('REQUEST_METHOD', 'GET', 'string')) !== 'POST') {
			$this->sendJson(
				['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Method must be POST']],
				405
			);
			return;
		}

		$user = $app->getIdentity();
		if ($user === null) {
			$user = Factory::getUser();
		}

		$raw = (string) file_get_contents('php://input');

		try {
			$message = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$this->sendJson(
				['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: ' . $e->getMessage()]],
				400
			);
			return;
		}

		$registry = new ToolRegistry();
		$dispatcher = $app->getDispatcher();
		$dispatcher->dispatch(RegisterToolsEvent::EVENT_NAME, new RegisterToolsEvent($registry));

		// Server-side filters and the secret-guard's secret list. Built here
		// because they all depend on request state (URL query, component config,
		// authenticated session) that the Server itself shouldn't have to know.
		$categoryFilter = $this->parseCategoryFilter($app);
		$readOnlyMode   = $this->resolveReadOnlyMode($app);
		$secrets        = $this->collectSecretsToGuard();

		$server   = new Server($registry, $user, $categoryFilter, $readOnlyMode, $secrets);
		$response = $server->handle($message);

		if ($response === null) {
			// Notification(s) only — MCP spec says return 202 Accepted with empty body
			$app->setHeader('status', '202', true);
			$app->sendHeaders();
			$app->close();
			return;
		}

		$this->sendJson($response, 200);
	}

	/**
	 * GET handler for the same /v1/mcp route. The endpoint is JSON-RPC over
	 * POST and MCP clients never GET it — but humans landing here from a
	 * dashboard link or curl test deserve a friendlier response than
	 * Joomla's bare "Resource not found" 404. Returns a discovery payload
	 * with server info, the expected POST contract, and a pointer to docs.
	 *
	 * Public on purpose: no auth required for the discovery response so
	 * operators debugging connection problems can hit the URL without
	 * needing to assemble a token first. The actual MCP protocol surface
	 * stays POST-only and token-gated.
	 */
	public function info(): void
	{
		$app  = Factory::getApplication();
		$root = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');

		$payload = [
			'service'           => 'cs-mcp-for-j',
			'description'       => 'Model Context Protocol (MCP) endpoint for this Joomla site. Lets MCP clients (Claude Desktop, Claude Code, Cursor, Continue, Cline, etc.) call the site\'s registered tools over JSON-RPC 2.0.',
			'endpoint'          => $root . '/api/index.php/v1/mcp',
			'protocol'          => 'JSON-RPC 2.0 over HTTP',
			'method'            => 'POST (only — GET returns this info response)',
			'content_type'      => 'application/json',
			'authentication'    => 'Bearer <joomla-api-token> in the Authorization header (Joomla API tokens are created at User Profile → Joomla API Token)',
			'example_request'   => [
				'method'  => 'POST',
				'headers' => [
					'Authorization' => 'Bearer YOUR_JOOMLA_API_TOKEN_HERE',
					'Content-Type'  => 'application/json',
				],
				'body' => [
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
				],
			],
			'note' => 'You are seeing this response because you hit the endpoint with GET (e.g. clicked the URL in a browser). MCP clients use POST and you do not interact with this URL directly — the client talks to it for you.',
		];

		// No auth check here — the discovery response is intentionally public.
		// Run inside the same output-buffer guard as handle() so a stray
		// notice from elsewhere can't corrupt the JSON.
		ob_start();
		try {
			$this->sendJson($payload, 200);
		} finally {
			if (ob_get_level() > 0) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Parse the optional `?categories=articles,users` query parameter into a
	 * list of lowercase category slugs. Also accepts `?category=` (singular)
	 * for convenience.
	 *
	 * @return array<int, string>
	 */
	private function parseCategoryFilter(CMSApplication $app): array
	{
		$raw = trim((string) $app->input->getString('categories', ''));
		if ($raw === '') {
			$raw = trim((string) $app->input->getString('category', ''));
		}
		if ($raw === '') {
			return [];
		}

		return array_values(array_filter(array_map(
			static fn(string $s): string => strtolower(trim($s)),
			explode(',', $raw)
		)));
	}

	/**
	 * Read the component's `read_only_mode` config. Set via Options on the
	 * com_csmcpforj admin page — when on, the server announces and accepts
	 * only tools with the MCP `readOnlyHint` annotation.
	 *
	 * Also honors a per-request override `?read_only=1` for sessions an
	 * operator wants to scope tighter than the global default. The reverse
	 * (`?read_only=0` to unlock writes from a read-only-mode site) is NOT
	 * accepted — operators control the floor, clients can only narrow.
	 */
	private function resolveReadOnlyMode(CMSApplication $app): bool
	{
		$siteDefault = (bool) ComponentHelper::getParams('com_csmcpforj')->get('read_only_mode', 0);

		if ($siteDefault) {
			return true;
		}

		return (bool) $app->input->getBool('read_only', false);
	}

	/**
	 * Collect strings that must not appear in any tool argument. Today this is
	 * the inbound request's Bearer token (translated to X-Joomla-Token by
	 * plg_system_csmcpforj earlier in the request) — short, high-value, and
	 * the most plausible target of a prompt-injection attempt.
	 *
	 * Returning an empty array (e.g. dev mode with no token) silently disables
	 * the guard for this request — ArgumentSecretGuard already filters short /
	 * empty secrets so callers don't have to.
	 *
	 * @return array<int, string>
	 */
	private function collectSecretsToGuard(): array
	{
		$secrets = [];

		$token = (string) ($_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? '');
		if ($token !== '') {
			$secrets[] = $token;
		}

		$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
		if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
			$bearer = trim(substr($auth, 7));
			if ($bearer !== '') {
				$secrets[] = $bearer;
			}
		}

		return $secrets;
	}

	private function sendJson(array $payload, int $status): void
	{
		$app = Factory::getApplication();

		// Discard anything that landed in the output buffer before we
		// write the response — defends against the PHP-warning-corrupts-
		// JSON-RPC class of bugs even on the first sendJson exit path.
		// We don't care what was in there; if it was real output we'd
		// have already been told via an exception.
		if (ob_get_level() > 0) {
			ob_clean();
		}

		$app->setHeader('status', (string) $status, true);
		$app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$app->setHeader('Cache-Control', 'no-store', true);
		$app->sendHeaders();
		echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$app->close();
	}
}
