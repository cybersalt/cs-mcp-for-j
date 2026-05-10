<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Api\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\Server;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Application\CMSApplication;
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

		$server   = new Server($registry, $user);
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
