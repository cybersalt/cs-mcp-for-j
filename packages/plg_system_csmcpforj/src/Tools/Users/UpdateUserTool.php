<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateUserTool extends AbstractTool
{
	private const UPDATABLE = ['name', 'username', 'email', 'block', 'sendEmail', 'requireReset'];

	public function getName(): string { return 'update_user'; }

	public function getDescription(): string
	{
		return 'Update an existing user. Required: id. Pass groups[] to replace the entire '
			. 'set of group memberships. Pass password (min 12 chars) to reset the password.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'           => ['type' => 'integer'],
				'name'         => ['type' => 'string'],
				'username'     => ['type' => 'string'],
				'email'        => ['type' => 'string'],
				'password'     => ['type' => 'string', 'minLength' => 12],
				'groups'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'block'        => ['type' => 'integer', 'enum' => [0, 1]],
				'sendEmail'    => ['type' => 'integer', 'enum' => [0, 1]],
				'requireReset' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$model    = $this->getModel('com_users', 'User');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('User ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (!empty($arguments['password'])) {
			if (strlen((string) $arguments['password']) < 12) {
				return ToolResult::error('password must be at least 12 characters.');
			}
			$data['password']  = $arguments['password'];
			$data['password2'] = $arguments['password'];
		}
		if (isset($arguments['groups']) && is_array($arguments['groups'])) {
			$data['groups'] = array_map('intval', $arguments['groups']);
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_users rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
