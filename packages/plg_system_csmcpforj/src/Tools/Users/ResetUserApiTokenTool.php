<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;

final class ResetUserApiTokenTool extends AbstractTool
{
	public function getName(): string { return 'reset_user_api_token'; }

	public function getDescription(): string
	{
		return 'Generate a fresh API token for a user and return the new display string '
			. '(format "<algo>:<userid>:<hmac>", base64-encoded). The user can paste this '
			. 'directly into an MCP client\'s Authorization: Bearer header. Also sets the '
			. 'token to enabled. Any previously-issued token for the user is invalidated.';
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
		$result = $helper->reset($userId);

		if ($result['display_token'] === '') {
			return ToolResult::error(
				'Token reset succeeded but the display token could not be computed. '
				. 'Joomla\'s site secret may be empty — check configuration.php.'
			);
		}

		return ToolResult::json([
			'ok'             => true,
			'user_id'        => $userId,
			'display_token'  => $result['display_token'],
			'paste_as'       => 'Authorization: Bearer ' . $result['display_token'],
		] + $result['status']);
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
