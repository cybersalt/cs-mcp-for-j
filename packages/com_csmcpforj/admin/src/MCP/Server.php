<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\Security\ArgumentSecretGuard;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Throwable;

/**
 * MCP JSON-RPC 2.0 server. Handles a single message or a batch and returns the
 * responses as plain arrays (the controller serialises them and writes the
 * HTTP response).
 *
 * v1 surface: initialize, notifications/initialized, ping, tools/list, tools/call.
 *
 * Constructor accepts two optional server-side filters:
 *   - $categoryFilter: lowercase domain slugs ('articles', 'users', ...) the
 *     session is scoped to. Empty array = no filter. Backed by the
 *     `?categories=articles,users` query parameter on the HTTP endpoint.
 *   - $readOnlyMode: when true, the session can only see/call tools whose
 *     ToolAnnotations declare readOnlyHint=true. Backed by the component's
 *     read_only_mode config switch.
 *
 * Both filters apply consistently to tools/list AND tools/call so a client
 * cannot invoke a tool that the filtered tools/list response wouldn't have
 * shown them.
 *
 * Constructor also accepts an optional $secrets array — strings that must not
 * appear in any tool argument. The McpController populates this with the
 * authenticated Bearer token so a prompt-injection attempt can't smuggle the
 * token into a downstream tool call.
 */
final class Server
{
	public const PROTOCOL_VERSION_DEFAULT = '2025-06-18';
	public const SERVER_NAME              = 'cs-mcp-for-j';
	public const SERVER_VERSION           = '1.8.1';

	/** @var array<int, string> */
	private array $categoryFilter;

	private bool $readOnlyMode;

	/** @var array<int, string> */
	private array $secrets;

	public function __construct(
		private readonly ToolRegistry $registry,
		private readonly User $actor,
		array $categoryFilter = [],
		bool $readOnlyMode = false,
		array $secrets = []
	) {
		$this->categoryFilter = $categoryFilter;
		$this->readOnlyMode   = $readOnlyMode;
		$this->secrets        = $secrets;
	}

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

		return ['tools' => $this->registry->describeForMcp($this->categoryFilter, $this->readOnlyMode)];
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

		// Enforce the same category + read-only filters on tools/call as on
		// tools/list — otherwise a client could call a tool by name that
		// the filtered tools/list response wouldn't have shown them, defeating
		// the whole "scoped session" guarantee.
		if (!$this->registry->isCallable($name, $this->categoryFilter, $this->readOnlyMode)) {
			throw new McpException(
				-32601,
				'Tool "' . $name . '" is not available in this session. '
				. ($this->readOnlyMode ? '(server is in read-only mode) ' : '')
				. ($this->categoryFilter !== []
					? '(session scoped to categories: ' . implode(', ', $this->categoryFilter) . ')'
					: '')
			);
		}

		if ($tool->getRequiredPermission() === 'write') {
			PermissionHelper::requireWrite($this->actor);
		} else {
			PermissionHelper::requireUse($this->actor);
		}

		// Prompt-injection circuit breaker. If any tool argument contains the
		// session's bearer token (or any other secret the controller chose to
		// pass in), refuse the call before the tool handler runs. See
		// ArgumentSecretGuard for the threat model.
		ArgumentSecretGuard::assertNoSecretsIn($arguments, $this->secrets);

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
