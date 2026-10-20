<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class CreateUserGroupTool extends AbstractTool
{
	public function getName(): string { return 'create_user_group'; }

	public function getDescription(): string
	{
		return 'Create a new Joomla user group under #__usergroups. Required: title. '
			. 'parent_id defaults to 1 (Public). Goes through com_users\' GroupModel so the '
			. 'matching #__assets row is created and the group inherits permissions from its '
			. 'parent. After creation, use set_component_permission to grant specific '
			. 'component access; use update_user(groups=[...]) to assign the group to users.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title'],
			'properties' => [
				'title'     => ['type' => 'string', 'description' => 'Group title. Must be unique among the groups with the same parent.'],
				'parent_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the parent group (list_user_groups). Defaults to 1 (Public). Joomla allows only one root group, so 0 is refused. Only a Super User can create a group under a group that has Super User rights.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title    = $this->requireString($arguments, 'title');
		$parentId = array_key_exists('parent_id', $arguments) ? (int) $arguments['parent_id'] : 1;

		// Table\Usergroup::check() refuses these too, but with a bare
		// "parent not valid"; say which group id was wrong.
		if ($parentId <= 0 || !$this->groupExists($parentId)) {
			return ToolResult::error('parent_id must be the id of an existing user group (list_user_groups); got ' . json_encode($arguments['parent_id'] ?? null) . '.');
		}

		$data = [
			'id'        => 0,
			'title'     => $title,
			'parent_id' => $parentId,
		];

		$model  = $this->getModel('com_users', 'Group');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('com_users rejected the group: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = [
			'ok'        => true,
			'id'        => $result['id'],
			'title'     => $title,
			'parent_id' => $parentId,
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}

	private function groupExists(int $id): bool
	{
		return (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__usergroups'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadResult() > 0;
	}
}
