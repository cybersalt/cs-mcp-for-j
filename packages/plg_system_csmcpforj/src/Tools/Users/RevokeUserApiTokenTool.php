<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;

final class RevokeUserApiTokenTool extends AbstractTool
{
	public function getName(): string { return 'revoke_user_api_token'; }

	public function getDescription(): string
	{
		return 'Disable a user\'s API token and zero the stored secret. Use for incident '
			. 'response (suspected leak) or when retiring a service account. To restore '
			. 'access, call reset_user_api_token which generates a new secret.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['user_id'],
			'properties' => [
				'user_id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$userId = $this->requirePositiveInt($arguments, 'user_id');
		if (!$this->userExists($userId)) {
			return ToolResult::error('User ' . $userId . ' not found.');
		}
		$this->requireCanEditTargetUser($actor, $userId);

		$helper = new JoomlatokenHelper($this->db);
		$status = $helper->revoke($userId);

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
