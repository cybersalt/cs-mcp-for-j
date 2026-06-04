<?php

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
				'title'     => ['type' => 'string'],
				'parent_id' => ['type' => 'integer', 'description' => 'Defaults to 1 (Public). Set 0 for a top-level group with no parent — rarely what you want.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title    = $this->requireString($arguments, 'title');
		$parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1;

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
}
