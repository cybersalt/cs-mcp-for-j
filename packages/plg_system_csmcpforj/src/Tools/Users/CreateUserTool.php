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
use Cybersalt\Plugin\System\Csmcpforj\Helper\JoomlatokenHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;

final class CreateUserTool extends AbstractTool
{
	use UserArgumentsTrait;

	public function getName(): string { return 'create_user'; }

	public function getDescription(): string
	{
		return 'Create a new Joomla user — the fields on the Account Details, Assigned User Groups, Basic Settings '
			. 'and Accessibility Settings tabs. Required: name, username, email, password (min 12 chars, and the '
			. 'site\'s Password Options). Optional: groups[] (user-group ids; defaults to Registered, group 2), '
			. 'block (default 0), sendEmail (default 0), requireReset (default 0), params (user settings). '
			. 'Only a Super User can create a user in a Super User group. Set enable_api_token=true '
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
				'username'     => ['type' => 'string', 'description' => 'Login name. At least 2 characters; no < > " \' % ; ( ) & \\ or leading/trailing spaces.'],
				'email'        => ['type' => 'string'],
				'password'     => ['type' => 'string', 'minLength' => 12, 'description' => 'Plain text — Joomla hashes it before storage. Min 12 chars, and must meet the site\'s Password Options.'],
				'groups'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User group ids (list_user_groups). Default [2] (Registered).'],
				'block'        => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = blocked (cannot log in), 0 = enabled.'],
				'sendEmail'    => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Receive System Emails.'],
				'requireReset' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Require Password Reset at first login.'],
				'params'       => ['type' => 'object', 'additionalProperties' => true, 'description' => self::userParamsDescription('omitted keys stay at Use Default.')],
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
		// Not requireString(): that trims, which would silently store a
		// different password from the one the caller was given. The site's
		// password rule refuses leading/trailing spaces instead.
		$password = is_string($arguments['password'] ?? null) ? $arguments['password'] : '';

		$this->checkUserPassword($password);

		$groups = array_key_exists('groups', $arguments)
			? $this->userGroupIds($arguments['groups'])
			: [2];

		$flags = [];
		foreach (['block', 'sendEmail', 'requireReset'] as $flag) {
			$v = $arguments[$flag] ?? 0;
			if (!in_array($v, [0, 1, '0', '1', true, false], true)) {
				return ToolResult::error($flag . ' must be 0 or 1; got ' . json_encode($v) . '.');
			}
			$flags[$flag] = (int) $v;
		}

		$params = array_key_exists('params', $arguments)
			? $this->mergeJsonPatch([], $this->userParamsPatch($arguments['params']))
			: [];

		$data = [
			'id'           => 0,
			'name'         => $name,
			'username'     => $username,
			'email'        => $email,
			'password'     => $password,
			'password2'    => $password,
			'block'        => $flags['block'],
			'sendEmail'    => $flags['sendEmail'],
			'requireReset' => $flags['requireReset'],
			'groups'       => $groups,
			'registerDate' => gmdate('Y-m-d H:i:s'),

			/*
			 * An ARRAY, not the string '{}'. Issue #15.
			 *
			 * UserModel::save() hands $data straight to User::bind(), which on
			 * Joomla 5/6 does
			 *
			 *     $this->_params->loadArray($array['params']);   // User.php:669
			 *
			 * and Registry::loadArray() is typed `array`. A JSON string therefore
			 * raises `Registry::loadArray(): Argument #1 ($array) must be of type
			 * array, string given` and no user is created at all. An empty array
			 * is the correct "no params" value; bind() serialises it on the way
			 * to the column.
			 */
			'params'       => $params,
		];

		// The new id is found by username after the save (below). If that
		// username already belonged to someone, the save fails with
		// USERNAME_INUSE but the lookup would still find the OLD account and
		// report it as created — and enable_api_token would then act on it.
		$taken = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('id'))
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('username') . ' = ' . $this->db->quote($username))
		)->loadResult();

		if ($taken > 0) {
			return ToolResult::error('The username "' . $username . '" is already in use (user ' . $taken . ').');
		}

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

		if ($params !== []) {
			$response['params'] = (object) $params;
		}

		if (!empty($arguments['enable_api_token'])) {
			// Joomla's UserModel already enforced "actor can edit this user" via
			// group-assignment checks during save, but recheck here in case a
			// post-save plugin chain mutated the new user's groups.
			//
			// The user exists by now. A refusal here must not come back as a
			// failed create_user — the caller would retry and hit "username in
			// use" — so it is reported as a token_warning on a successful create.
			try {
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
			} catch (\Throwable $e) {
				$response['token_warning'] = 'User created, but no API token was minted: ' . $e->getMessage();
			}
		}

		return ToolResult::json($response);
	}
}
