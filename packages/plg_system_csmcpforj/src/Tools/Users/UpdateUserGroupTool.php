<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

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
			'fields_changed' => array_values(array_diff(array_keys($data), ['id'])),
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
