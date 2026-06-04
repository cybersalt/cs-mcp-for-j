<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;

final class CreateUserTool extends AbstractTool
{
	public function getName(): string { return 'create_user'; }

	public function getDescription(): string
	{
		return 'Create a new Joomla user. Required: name, username, email, password (min 12 chars). '
			. 'Optional: groups[] (array of user-group ids; defaults to Registered, group 2). '
			. 'block defaults to 0, sendEmail to 0, requireReset to 0. Set enable_api_token=true '
			. 'to mint a fresh Joomla API token for the new user in the same call — the response '
			. 'will include a display_token the user can paste into an MCP client immediately.';
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
				'enable_api_token' => ['type' => 'boolean', 'description' => 'If true, mint a Joomla API token for the new user and return a display_token in the response.'],
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

		$model  = $this->getModel('com_users', 'User');
		$ok     = (bool) $model->save($data);
		$error  = (string) ($model->getError() ?: '');

		// Find the new id by username — model's getState('user.id') is unreliable
		// for com_users. The username row is the truthful signal: if it exists,
		// the user was created regardless of what post-save plugins reported.
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__users'))
			->where($this->db->quoteName('username') . ' = ' . $this->db->quote($username));
		$id = (int) $this->db->setQuery($query)->loadResult();

		if ($id <= 0) {
			return ToolResult::error('com_users rejected the user: ' . ($error ?: 'unknown error'));
		}

		$response = [
			'ok'       => true,
			'id'       => $id,
			'username' => $username,
			'email'    => $email,
			'groups'   => $groups,
			'edit_url' => 'index.php?option=com_users&task=user.edit&id=' . $id,
		];
		if (!$ok && $error !== '') {
			$response['post_save_warning'] = $error;
		}

		if (!empty($arguments['enable_api_token'])) {
			// Joomla's UserModel already enforced "actor can edit this user" via
			// group-assignment checks during save, but recheck here in case a
			// post-save plugin chain mutated the new user's groups.
			$this->requireCanEditTargetUser($actor, $id);

			$helper      = new JoomlatokenHelper($this->db);
			$tokenResult = $helper->reset($id);
			if ($tokenResult['display_token'] !== '') {
				$response['display_token']   = $tokenResult['display_token'];
				$response['paste_as']        = 'Authorization: Bearer ' . $tokenResult['display_token'];
				$response['token_enabled']   = $tokenResult['status']['enabled'] ?? true;
			} else {
				$response['token_warning'] = 'Token mint succeeded but display string is empty — site secret may be missing in configuration.php.';
			}
		}

		return ToolResult::json($response);
	}
}
