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
 */
final class UpdateUserGroupTool extends AbstractTool
{
	public function getName(): string { return 'update_user_group'; }

	public function getDescription(): string
	{
		return 'Update an existing user group. Required: id. Only fields you supply are changed. '
			. 'Moving a group under a new parent_id changes which permissions it inherits — '
			. 'check list_component_permissions on affected components after a parent change.';
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

		$changed = array_values(array_diff(array_keys($data), ['id']));

		// GroupModel::save() runs its Super User check on $data['parent_id']
		// before bind; see the class docblock.
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing['parent_id'];
		}

		$model  = $this->getModel('com_users', 'Group');
		$result = $this->saveAdminModel($model, $data);

		if (!$result['ok'] && $result['error'] !== '') {
			$check = $this->loadGroup($id);
			if ($check === null) {
				return ToolResult::error('com_users rejected the update: ' . $result['error']);
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

	private function loadGroup(int $id): ?array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'parent_id']))
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		return $this->db->setQuery($q)->loadAssoc() ?: null;
	}
}
