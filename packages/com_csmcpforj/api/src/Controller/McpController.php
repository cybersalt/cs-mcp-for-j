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
 */
final class McpController extends BaseController
{
	public function handle(): void
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
		$app->setHeader('status', (string) $status, true);
		$app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$app->setHeader('Cache-Control', 'no-store', true);
		$app->sendHeaders();
		echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$app->close();
	}
}
