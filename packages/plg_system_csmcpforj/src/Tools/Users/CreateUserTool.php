<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;

final class CreateUserTool extends AbstractTool
{
	public function getName(): string { return 'create_user'; }

	public function getDescription(): string
	{
		return 'Create a new Joomla user. Required: name, username, email, password (min 12 chars). '
			. 'Optional: groups[] (array of user-group ids; defaults to Registered, group 2). '
			. 'block defaults to 0, sendEmail to 0, requireReset to 0.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['name', 'username', 'email', 'password'],
			'properties' => [
				'name'         => ['type' => 'string'],
				'username'     => ['type' => 'string'],
				'email'        => ['type' => 'string'],
				'password'     => ['type' => 'string', 'minLength' => 12, 'description' => 'Plain text — will be hashed before storage. Min 12 chars.'],
				'groups'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User group ids. Default [2] (Registered).'],
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
		$name     = $this->requireString($arguments, 'name');
		$username = $this->requireString($arguments, 'username');
		$email    = $this->requireString($arguments, 'email');
		$password = $this->requireString($arguments, 'password');

		if (strlen($password) < 12) {
			return ToolResult::error('password must be at least 12 characters.');
		}

		$groups = isset($arguments['groups']) && is_array($arguments['groups'])
			? array_map('intval', $arguments['groups'])
			: [2];

		$data = [
			'id'           => 0,
			'name'         => $name,
			'username'     => $username,
			'email'        => $email,
			'password'     => $password,
			'password2'    => $password,
			'block'        => isset($arguments['block']) ? (int) $arguments['block'] : 0,
			'sendEmail'    => isset($arguments['sendEmail']) ? (int) $arguments['sendEmail'] : 0,
			'requireReset' => isset($arguments['requireReset']) ? (int) $arguments['requireReset'] : 0,
			'groups'       => $groups,
			'registerDate' => gmdate('Y-m-d H:i:s'),
			'params'       => '{}',
		];

		$model = $this->getModel('com_users', 'User');
		if (!$model->save($data)) {
			return ToolResult::error('com_users rejected the user: ' . $model->getError());
		}

		// Find the new id by username (model's getState id is unreliable here)
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__users'))
			->where($this->db->quoteName('username') . ' = ' . $this->db->quote($username));
		$id = (int) $this->db->setQuery($query)->loadResult();

		return ToolResult::json([
			'ok'       => true,
			'id'       => $id,
			'username' => $username,
			'email'    => $email,
			'groups'   => $groups,
			'edit_url' => 'index.php?option=com_users&task=user.edit&id=' . $id,
		]);
	}
}
