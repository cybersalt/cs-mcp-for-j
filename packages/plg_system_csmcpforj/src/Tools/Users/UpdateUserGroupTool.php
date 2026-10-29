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

/**
 * @partial-save-safe The row is safe — GroupModel::save() binds only
 * supplied keys. Verified behaviourally on Joomla 6.1.4, 2026-10-09: created a
 * group, changed only `title`, re-read from list_user_groups and diffed —
 * nothing else moved.
 *
 * ⚠️ That diff could not see the permission check. com_users' GroupModel::
 * save() reads $data['parent_id'] BEFORE bind, unconditionally, to work out
 * whether the group will carry core.admin (Access::checkGroup($data['parent_id'],
 * 'core.admin')). With the key absent that resolves to "inherit", so a title-
 * only update of a group under Super Users was checked as an ordinary group:
 * a non-Super-User actor got past JLIB_USER_ERROR_NOT_SUPERADMIN, and a Super
 * User whose rights come through that group could be refused with
 * CANNOT_DEMOTE_SELF. parent_id is now re-supplied from the row (reviewed
 * against Joomla 6.2.0, 2026-10-19).
 *
 * Field coverage, Joomla 6.2.0 com_users/forms/group.xml (2026-10-20): the
 * form has only title and parent_id (id, lft, rgt and actions are hidden or
 * unset), so this tool covers the whole screen. The Permissions tab of the
 * Global Configuration is set_component_permission's job.
 */
final class UpdateUserGroupTool extends AbstractTool
{
	use UsersAclTrait;

	public function getName(): string { return 'update_user_group'; }

	public function getDescription(): string
	{
		return 'Update an existing user group — the Group Title and Group Parent fields of the group edit screen '
			. '(its permissions are set with set_component_permission). Required: id. Only fields you supply are changed. '
			. 'Moving a group under a new parent_id changes which permissions it inherits — '
			. 'check list_component_permissions on affected components after a parent change. '
			. 'A group cannot be moved under itself or one of its own descendants, and Public cannot be moved.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id'        => ['type' => 'integer'],
				'title'     => ['type' => 'string'],
				'parent_id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$this->requireUsersRight($actor, 'core.edit', 'editing a user group');

		$existing = $this->loadGroup($id);
		if ($existing === null) {
			return ToolResult::error('User group ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		if (array_key_exists('title', $arguments)) {
			$data['title'] = (string) $arguments['title'];
		}
		if (array_key_exists('parent_id', $arguments)) {
			$data['parent_id'] = (int) $arguments['parent_id'];
		}

		if (count($data) === 1) {
			return ToolResult::error('No updatable fields supplied. Pass title and/or parent_id.');
		}

		if (array_key_exists('title', $data) && trim($data['title']) === '') {
			return ToolResult::error('title cannot be empty.');
		}

		if (array_key_exists('parent_id', $data) && $data['parent_id'] !== (int) $existing['parent_id']) {
			$refusal = $this->parentRefusal($existing, $data['parent_id']);
			if ($refusal !== null) {
				return ToolResult::error($refusal);
			}
		}

		$changed = array_values(array_diff(array_keys($data), ['id']));

		// GroupModel::save() runs its Super User check on $data['parent_id']
		// before bind; see the class docblock.
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing['parent_id'];
		}

		$model  = $this->getModel('com_users', 'Group');
		$result = $this->saveAdminModel($model, $data);

		if (!$result['ok']) {
			/*
			 * The group always still exists, so "does the row exist" (the old
			 * check here) reported every refusal — JLIB_USER_ERROR_NOT_SUPERADMIN,
			 * CANNOT_DEMOTE_SELF, a duplicate title — as ok. What matters is
			 * whether the requested values landed.
			 */
			$check  = $this->loadGroup($id);
			$landed = $check !== null
				&& (!array_key_exists('title', $arguments) || (string) $check['title'] === $data['title'])
				&& (int) $check['parent_id'] === $data['parent_id'];

			if (!$landed) {
				return ToolResult::error('com_users rejected the update: ' . ($result['error'] ?: 'unknown error'));
			}
		}

		$response = [
			'ok'             => true,
			'id'             => $id,
			'fields_changed' => $changed,
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}

	/**
	 * Why a move to $newParentId must be refused, or null if it may go ahead.
	 *
	 * The edit screen's Group Parent list (GroupparentField) leaves out the
	 * group itself and all its descendants. GroupModel::save() and
	 * Table\Usergroup::check() only refuse the group itself, so a move under
	 * one of its own children would be stored: the subtree then forms a loop
	 * that Usergroup::rebuild() never reaches from the root, and those groups
	 * keep stale lft/rgt values — breaking every permission lookup that walks
	 * the tree. Refused here instead.
	 */
	private function parentRefusal(array $group, int $newParentId): ?string
	{
		if ((int) $group['parent_id'] === 0) {
			return 'Group ' . $group['id'] . ' is the root group (Public); it cannot be moved.';
		}

		if ($newParentId <= 0) {
			return 'parent_id must be the id of an existing user group; Joomla allows only one root group.';
		}

		$parent = $this->loadGroup($newParentId);
		if ($parent === null) {
			return 'parent_id ' . $newParentId . ' is not an existing user group (list_user_groups).';
		}

		if ((int) $parent['lft'] >= (int) $group['lft'] && (int) $parent['rgt'] <= (int) $group['rgt']) {
			return 'parent_id ' . $newParentId . ' is group ' . $group['id'] . ' itself or one of its descendants; a group cannot be moved under itself.';
		}

		return null;
	}

	private function loadGroup(int $id): ?array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'parent_id', 'lft', 'rgt']))
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		return $this->db->setQuery($q)->loadAssoc() ?: null;
	}
}
