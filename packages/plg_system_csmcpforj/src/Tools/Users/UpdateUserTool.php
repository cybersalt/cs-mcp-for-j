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
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * @partial-save-safe NOT safe by the model — made safe by this tool.
 * com_users reads $data['groups'] as the complete group list, so omitting it
 * meant "remove every group" and the model refused the whole save with
 * COM_USERS_USERS_ERROR_CANNOT_SAVE_ACCOUNT_WITHOUT_GROUPS. No partial
 * update worked at all. existingUserGroups() now re-supplies them when the
 * caller does not. Verified on Joomla 6.1.4, 2026-10-09: renaming a user
 * succeeds and group membership survives.
 *
 * Reviewed against Joomla 6.2.0 source, 2026-10-20, when params was added:
 *   - UserModel::save() reads $data['block'] and $data['groups'] before bind;
 *     both are re-supplied from the stored user when omitted.
 *   - User::bind() starts from the user as loaded, so name, username, email,
 *     sendEmail and requireReset keep their stored values when omitted, and
 *     the stored password hash is kept when no password is passed.
 *   - User::bind() only touches params when $data['params'] is present; it
 *     then MERGES into the loaded params (Registry::loadArray), so a patch of
 *     one key leaves the others alone. Merge-only is also why a key cannot be
 *     deleted: null maps to "" (Use Default), which is what Joomla stores.
 *   - registerDate, lastvisitDate, lastResetTime and resetCount are unset by
 *     User::bind() on every update, so they cannot move.
 * The params behaviour is from reading the source; the live one-field diff
 * (see tests/partial-save-guard.php) has not been re-run for it yet.
 */
final class UpdateUserTool extends AbstractTool
{
	use UserArgumentsTrait;
	use UsersAclTrait;
	use UserProfileTrait;

	private const UPDATABLE = ['name', 'username', 'email', 'block', 'sendEmail', 'requireReset'];

	public function getName(): string { return 'update_user'; }

	public function getDescription(): string
	{
		return 'Update an existing user — the fields on the Account Details, Assigned User Groups, Basic Settings '
			. 'and Accessibility Settings tabs. Required: id. Only fields you supply are changed. '
			. 'groups[] replaces the entire set of group memberships. params is merged key by key. '
			. 'password (min 12 chars, and the site\'s Password Options) sets a new password and signs the user out '
			. 'everywhere; block=1 also signs them out. Uses Joomla\'s own Users permissions: editing another user needs Edit '
			. 'on Users, blocking or unblocking them needs Edit State, and only a Super User can change a Super User. '
			. 'You cannot block yourself, set requireReset on yourself, or change your own groups unless you are a Super User.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'           => ['type' => 'integer'],
				'name'         => ['type' => 'string'],
				'username'     => ['type' => 'string', 'description' => 'Login name. At least 2 characters; no < > " \' % ; ( ) & \\ or leading/trailing spaces.'],
				'email'        => ['type' => 'string'],
				'password'     => ['type' => 'string', 'minLength' => 12, 'description' => 'New password, plain text — Joomla hashes it. Must meet the site\'s Password Options. Signs the user out of every session.'],
				'groups'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Complete list of user group ids (list_user_groups). Replaces the current memberships.'],
				'block'        => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = blocked (cannot log in), 0 = enabled.'],
				'sendEmail'    => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Receive System Emails.'],
				'requireReset' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Require Password Reset at next login.'],
				'params'       => ['type' => 'object', 'additionalProperties' => true, 'description' => self::userParamsDescription('null or "" resets that key to Use Default.')],
				'profile'      => ['type' => 'object', 'additionalProperties' => true, 'description' => self::userProfileDescription()],
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
				$data[$key] = in_array($key, ['block', 'sendEmail', 'requireReset'], true)
					? $this->userFlag($arguments[$key], $key)
					: trim((string) $arguments[$key]);
			}
		}

		$changed = array_values(array_diff(array_keys($arguments), ['id']));
		if ($changed === []) {
			return ToolResult::error('No updatable fields supplied. Pass any of name, username, email, password, groups, block, sendEmail, requireReset, params, profile.');
		}

		// Joomla's Users screen rules (UsersAclTrait): Edit on Users for
		// another account, and only a Super User may change a Super User.
		$this->requireCanActOnUser($actor, $id, 'core.edit', 'editing another user');

		// Blocking or unblocking someone else is the list's Block/Unblock
		// action in Joomla, which needs Edit State.
		if (array_key_exists('block', $data) && (int) $actor->id !== $id && $data['block'] !== (int) $existing->block) {
			$this->requireUsersRight($actor, 'core.edit.state', 'blocking or unblocking a user');
		}

		// UserModel::save() quietly drops a group change on your own account
		// unless you are a Super User. Say so instead of reporting success.
		if (array_key_exists('groups', $arguments) && (int) $actor->id === $id && !$actor->authorise('core.admin')) {
			return ToolResult::error('Joomla does not let you change your own user groups unless you are a Super User.');
		}

		// Credentials of someone else's account: see requireCanChangeCredentials().
		$credentials = [];
		if (array_key_exists('password', $arguments)) {
			$credentials[] = 'password';
		}
		if (array_key_exists('email', $arguments) && strcasecmp((string) $data['email'], (string) $existing->email) !== 0) {
			$credentials[] = 'email';
		}
		if ($credentials !== []) {
			$this->requireCanChangeCredentials($actor, $id, $credentials);
		}

		// The edit form removes Require Password Reset from your own account.
		if (array_key_exists('requireReset', $data) && (int) $actor->id === $id && $data['requireReset'] !== (int) $existing->requireReset) {
			return ToolResult::error('Joomla does not let you set Require Password Reset on your own account.');
		}

		if (array_key_exists('password', $arguments)) {
			$password = (string) $arguments['password'];
			$this->checkUserPassword($password);
			$data['password']  = $password;
			$data['password2'] = $password;
		}

		if (array_key_exists('params', $arguments)) {
			// Stored params + the patch. User::bind() merges this into the
			// params it loaded too, so untouched keys survive either way.
			$data['params'] = $this->mergeJsonPatch($existing->params ?? null, $this->userParamsPatch($arguments['params']));
		}

		if (array_key_exists('profile', $arguments)) {
			$data['profile'] = $this->userProfilePatch($arguments['profile']);
		}

		if (array_key_exists('groups', $arguments)) {
			$data['groups'] = $this->userGroupIds($arguments['groups']);
		} else {
			/*
			 * Re-supply the user's existing groups when the caller did not pass
			 * any. Same hazard as #18, different model.
			 *
			 * com_users' UserModel reads $data['groups'] to mean "this is the
			 * complete group list". Omit it and it resolves to nothing, which the
			 * model treats as removing the user from every group — and then
			 * refuses the whole save with
			 * COM_USERS_USERS_ERROR_CANNOT_SAVE_ACCOUNT_WITHOUT_GROUPS.
			 *
			 * The effect was that no partial update worked at all: renaming a
			 * user, changing their email, blocking them, anything, failed unless
			 * the caller happened to re-send the full group list. Refusing is the
			 * kinder failure — it is not silent, and nothing is corrupted — but it
			 * is still a tool that cannot do its job.
			 *
			 * A caller who DOES pass groups still replaces the list outright,
			 * which is the documented behaviour.
			 */
			$existingGroups = $this->existingUserGroups($id);

			if ($existingGroups !== []) {
				$data['groups'] = $existingGroups;
			}
		}

		// UserModel::save() also reads $data['block'] before bind (the "cannot
		// block yourself" check). Absent, it is null — harmless to the check,
		// but an undefined-key warning that display_errors would print into
		// the response. Re-supply the current value.
		if (!array_key_exists('block', $data)) {
			$data['block'] = (int) $existing->block;
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_users rejected the update: ' . $model->getError());
		}

		$response = ['ok' => true, 'id' => $id, 'fields_changed' => $changed];

		if (array_key_exists('params', $arguments)) {
			$response['params'] = (object) $this->storedUserParams($id);
		}

		if (array_key_exists('profile', $arguments)) {
			$response['profile'] = (object) ($this->storedUserProfile($id) ?? []);
		}

		return ToolResult::json($response);
	}

	private function userFlag(mixed $value, string $key): int
	{
		if (!in_array($value, [0, 1, '0', '1', true, false], true)) {
			throw new McpException(-32602, $key . ' must be 0 or 1; got ' . json_encode($value) . '.');
		}

		return (int) $value;
	}

	/** @return array<string, mixed> */
	private function storedUserParams(int $userId): array
	{
		$raw = (string) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('params'))
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $userId)
		)->loadResult();

		$decoded = $raw !== '' ? json_decode($raw, true) : [];

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * The group ids this user is currently in.
	 *
	 * Read straight from #__user_usergroup_map rather than through the model,
	 * because the model is the thing we are about to hand a payload to and we
	 * want the row as it stands, not as the model reconstructs it.
	 *
	 * @return int[]
	 */
	private function existingUserGroups(int $userId): array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName('group_id'))
			->from($this->db->quoteName('#__user_usergroup_map'))
			->where($this->db->quoteName('user_id') . ' = ' . (int) $userId);

		return array_map('intval', (array) $this->db->setQuery($q)->loadColumn());
	}

}
