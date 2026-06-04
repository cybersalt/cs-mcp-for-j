<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

final class DeleteUserGroupTool extends AbstractTool
{
	public function getName(): string { return 'delete_user_group'; }

	public function getDescription(): string
	{
		return 'Delete a user group. Required: id, confirm:true. Refuses if any users are still '
			. 'in the group or if any child groups inherit from it — reassign first. Built-in '
			. 'Joomla groups (Public, Manager, Administrator, Registered, Author, Editor, '
			. 'Publisher, Super Users) cannot be deleted.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id', 'confirm'],
			'properties' => [
				'id'      => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'enum' => [true]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (empty($arguments['confirm'])) {
			return ToolResult::error('confirm:true is required to delete a user group.');
		}
		$id = $this->requirePositiveInt($arguments, 'id');

		$existing = $this->loadGroup($id);
		if ($existing === null) {
			return ToolResult::error('User group ' . $id . ' not found.');
		}

		$members = $this->countMembers($id);
		if ($members > 0) {
			return ToolResult::error(
				'Group ' . $id . ' has ' . $members . ' user(s) assigned. Reassign them via '
				. 'update_user(groups=[...]) before deleting.'
			);
		}

		$children = $this->countChildren($id);
		if ($children > 0) {
			return ToolResult::error(
				'Group ' . $id . ' has ' . $children . ' child group(s). Move or delete them first.'
			);
		}

		$model = $this->getModel('com_users', 'Group');
		$ids   = [$id];
		if (!$model->delete($ids)) {
			return ToolResult::error('com_users rejected the delete: ' . $model->getError());
		}

		return ToolResult::json([
			'ok'    => true,
			'id'    => $id,
			'title' => $existing['title'],
		]);
	}

	private function loadGroup(int $id): ?array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'parent_id']))
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		return $this->db->setQuery($q)->loadAssoc() ?: null;
	}

	private function countMembers(int $id): int
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName('#__user_usergroup_map'))
			->where($this->db->quoteName('group_id') . ' = ' . $id);
		return (int) $this->db->setQuery($q)->loadResult();
	}

	private function countChildren(int $id): int
	{
		$q = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('parent_id') . ' = ' . $id);
		return (int) $this->db->setQuery($q)->loadResult();
	}
}
