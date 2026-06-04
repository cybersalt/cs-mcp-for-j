<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;

final class EnableUserApiTokenTool extends AbstractTool
{
	public function getName(): string { return 'enable_user_api_token'; }

	public function getDescription(): string
	{
		return 'Toggle a user\'s API token enabled flag without touching the secret. '
			. 'Required: user_id, enabled (true to allow the existing token to authenticate, '
			. 'false to block it). If no secret has been generated yet, call '
			. 'reset_user_api_token instead — enable=true alone does not mint a token.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['user_id', 'enabled'],
			'properties' => [
				'user_id' => ['type' => 'integer'],
				'enabled' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$userId = $this->requirePositiveInt($arguments, 'user_id');
		if (!array_key_exists('enabled', $arguments)) {
			throw new \InvalidArgumentException('enabled is required.');
		}
		$enabled = (bool) $arguments['enabled'];

		if (!$this->userExists($userId)) {
			return ToolResult::error('User ' . $userId . ' not found.');
		}
		$this->requireCanEditTargetUser($actor, $userId);

		$helper = new JoomlatokenHelper($this->db);
		$status = $helper->setEnabled($userId, $enabled);

		return ToolResult::json(['ok' => true, 'user_id' => $userId] + $status);
	}

	private function userExists(int $userId): bool
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__users'))
			->where($this->db->quoteName('id') . ' = ' . $userId);
		return (bool) $this->db->setQuery($q)->loadResult();
	}
}
