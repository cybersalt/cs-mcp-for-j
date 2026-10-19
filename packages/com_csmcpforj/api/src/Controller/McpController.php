<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Api\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
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
			ActivityLog::warning('mcp', 'request', 'Request body was not valid JSON: ' . $e->getMessage(), [
				'user_id' => (int) $user->id,
				'details' => ['body_start' => mb_substr($raw, 0, 300), 'body_bytes' => strlen($raw)],
			]);
			$this->sendJson(
				['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: ' . $e->getMessage()]],
				400
			);
			return;
		}

		$registry = new ToolRegistry();
		$this->registerTools($app, $registry, (int) $user->id);

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
			'description'       => 'Model Context Protocol (MCP) endpoint for this Joomla site. Lets Codex and other conformant MCP clients (Claude, Cursor, Cline, Continue, ChatGPT custom connectors, GitHub Copilot, Gemini CLI, mcp-cli, etc.) call the site\'s registered tools over JSON-RPC 2.0.',
			'endpoint'          => $root . '/api/index.php/v1/mcp',
			'protocol'          => 'JSON-RPC 2.0 over HTTP',
			'method'            => 'POST (only — GET returns this info response)',
			'content_type'      => 'application/json',
			'authentication'    => 'Bearer <joomla-api-token> in the Authorization header (Joomla API tokens are created at User Profile → Joomla API Token), or OAuth 2.1 sign-in when the site has it switched on (discovery: /.well-known/oauth-protected-resource)',
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
			'note' => 'You are seeing this because you opened the endpoint in a browser. MCP clients talk to it with POST; a GET from an MCP client (with a token, or asking for an event stream) gets 405 Method Not Allowed from the system plugin before it reaches here, because this server does not offer a server-to-client stream.',
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
	/**
	 * Lets every plugin register its tools, one listener at a time, so a broken
	 * add-on costs only its own tools instead of the whole endpoint.
	 *
	 * A plain dispatch() runs the listeners in one loop, so a single throw (a
	 * tool class with a parse error, a missing class after a half-finished
	 * update, a constructor that fatals) aborted registration for everyone and
	 * the endpoint answered every request with HTTP 500, core tools included.
	 * Found 2026-10-19 on mcpfree when one add-on's quoting typo took MCP down
	 * for every client. On a customer site that left no MCP route to fix it.
	 *
	 * Each listener now runs on its own. If one throws, the tools it had
	 * already registered are dropped (half an add-on is worse than none), the
	 * failure goes to the activity log naming the add-on, file and line, and
	 * the remaining listeners still run. A tool it overwrote by name cannot be
	 * restored, but names are unique by convention (see ToolRegistry).
	 */
	private function registerTools(CMSApplication $app, ToolRegistry $registry, int $userId): void
	{
		$dispatcher = $app->getDispatcher();
		$event      = new RegisterToolsEvent($registry);

		if (!method_exists($dispatcher, 'getListeners')) {
			$dispatcher->dispatch(RegisterToolsEvent::EVENT_NAME, $event);
			return;
		}

		foreach ($dispatcher->getListeners(RegisterToolsEvent::EVENT_NAME) as $listener) {
			if ($event->isStopped()) {
				break;
			}

			$before = array_keys($registry->all());

			try {
				$listener($event);
			} catch (\Throwable $e) {
				$added = array_diff(array_keys($registry->all()), $before);

				foreach ($added as $name) {
					$registry->remove($name);
				}

				$addon = $this->describeListener($listener, $e);

				ActivityLog::exception('mcp', 'register_tools', $e, [
					'user_id' => $userId,
					'details' => [
						'addon'         => $addon,
						'tools_dropped' => array_values($added),
						'note'          => 'This add-on was skipped; every other add-on and the core tools are still available.',
					],
				]);
				error_log('cs-mcp-for-j: ' . $addon . ' failed to register its tools and was skipped: '
					. get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			}
		}
	}

	/**
	 * Names the add-on behind a failed listener: the plugin folder in the
	 * failing file's path when there is one (a parse error is thrown from the
	 * broken tool class, not from the listener), otherwise the listener class.
	 */
	private function describeListener(callable $listener, \Throwable $e): string
	{
		$path = str_replace('\\', '/', $e->getFile());

		if (preg_match('#/plugins/([^/]+)/([^/]+)/#', $path, $m)) {
			return 'plg_' . $m[1] . '_' . $m[2];
		}

		if (is_array($listener) && is_object($listener[0])) {
			return get_class($listener[0]);
		}

		return is_object($listener) ? get_class($listener) : 'unknown listener';
	}

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
