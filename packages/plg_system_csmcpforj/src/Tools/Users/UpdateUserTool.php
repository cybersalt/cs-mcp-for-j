<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
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
 */
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

		if (!$model->save($data)) {
			return ToolResult::error('com_users rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
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
