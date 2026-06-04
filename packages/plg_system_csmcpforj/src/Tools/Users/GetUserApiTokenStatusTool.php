<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;

final class GetUserApiTokenStatusTool extends AbstractTool
{
	public function getName(): string { return 'get_user_api_token_status'; }

	public function getDescription(): string
	{
		return 'Report whether a user has an API token configured. Returns enabled (true/false), '
			. 'has_secret (true/false), and algorithm. Does NOT reveal the secret. Use '
			. 'reset_user_api_token to mint a fresh display token.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['user_id'],
			'properties' => [
				'user_id' => ['type' => 'integer', 'description' => 'Joomla user id to inspect.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$userId = $this->requirePositiveInt($arguments, 'user_id');
		if (!$this->userExists($userId)) {
			return ToolResult::error('User ' . $userId . ' not found.');
		}
		$this->requireCanEditTargetUser($actor, $userId);

		$helper = new JoomlatokenHelper($this->db);
		return ToolResult::json(['user_id' => $userId] + $helper->status($userId));
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
