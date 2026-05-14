<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\PermissionHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Throwable;

/**
 * MCP JSON-RPC 2.0 server. Handles a single message or a batch and returns the
 * responses as plain arrays (the controller serialises them and writes the
 * HTTP response).
 *
 * v1 surface: initialize, notifications/initialized, ping, tools/list, tools/call.
 */
final class Server
{
	public const PROTOCOL_VERSION_DEFAULT = '2025-06-18';
	public const SERVER_NAME              = 'cs-mcp-for-j';
	public const SERVER_VERSION           = '1.7.5';

	public function __construct(
		private readonly ToolRegistry $registry,
		private readonly User $actor
	) {}

	/**
	 * Handle a parsed JSON-RPC message (object or batch).
	 *
	 * @param mixed $message Decoded JSON value
	 * @return array|null    Response payload to serialise, or null for notifications
	 */
	public function handle(mixed $message): array|null
	{
		if (is_array($message) && array_is_list($message)) {
			$responses = [];
			foreach ($message as $sub) {
				$resp = $this->handleSingle($sub);
				if ($resp !== null) {
					$responses[] = $resp;
				}
			}
			return $responses === [] ? null : $responses;
		}

		return $this->handleSingle($message);
	}

	private function handleSingle(mixed $message): ?array
	{
		if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !isset($message['method'])) {
			return $this->errorResponse(null, -32600, 'Invalid Request');
		}

		$id     = $message['id'] ?? null;
		$method = (string) $message['method'];
		$params = $message['params'] ?? [];
		$isNotification = !array_key_exists('id', $message);

		try {
			$result = match ($method) {
				'initialize'                => $this->handleInitialize($params),
				'notifications/initialized' => null,
				'notifications/cancelled'   => null,
				'ping'                      => new \stdClass(),
				'tools/list'                => $this->handleToolsList(),
				'tools/call'                => $this->handleToolsCall($params),
				default                     => throw new McpException(-32601, 'Method not found: ' . $method),
			};
		} catch (McpException $e) {
			return $isNotification ? null : $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData());
		} catch (Throwable $e) {
			return $isNotification ? null : $this->errorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
		}

		if ($isNotification) {
			return null;
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	private function handleInitialize(mixed $params): array
	{
		$clientVersion = is_array($params) ? (string) ($params['protocolVersion'] ?? '') : '';
		$negotiated    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $clientVersion)
			? $clientVersion
			: self::PROTOCOL_VERSION_DEFAULT;

		return [
			'protocolVersion' => $negotiated,
			'capabilities'    => [
				'tools' => ['listChanged' => false],
			],
			'serverInfo' => [
				'name'    => self::SERVER_NAME,
				'version' => self::SERVER_VERSION,
			],
		];
	}

	private function handleToolsList(): array
	{
		PermissionHelper::requireUse($this->actor);

		return ['tools' => $this->registry->describeForMcp()];
	}

	private function handleToolsCall(mixed $params): array
	{
		if (!is_array($params) || !isset($params['name'])) {
			throw new McpException(-32602, Text::_('COM_CSMCPFORJ_ERROR_INVALID_PARAMS'));
		}

		$name      = (string) $params['name'];
		$arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
		$tool      = $this->registry->get($name);

		if ($tool === null) {
			throw new McpException(-32602, Text::_('COM_CSMCPFORJ_ERROR_TOOL_NOT_FOUND') . ': ' . $name);
		}

		if ($tool->getRequiredPermission() === 'write') {
			PermissionHelper::requireWrite($this->actor);
		} else {
			PermissionHelper::requireUse($this->actor);
		}

		return $tool->execute($arguments, $this->actor)->toArray();
	}

	private function errorResponse(mixed $id, int $code, string $message, mixed $data = null): array
	{
		$error = ['code' => $code, 'message' => $message];
		if ($data !== null) {
			$error['data'] = $data;
		}

		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		];
	}
}
