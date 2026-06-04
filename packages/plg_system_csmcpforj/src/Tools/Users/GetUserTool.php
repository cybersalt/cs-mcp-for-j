<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetUserTool extends AbstractTool
{
	public function getName(): string { return 'get_user'; }

	public function getDescription(): string
	{
		return 'Fetch a single user by id (or by username). Returns the user record including '
			. 'group memberships. Password hash, otpKey, otep, and activation tokens are NEVER returned. '
			. 'Set include_profile=true to also return #__user_profiles rows (joomlatoken state, '
			. 'profile plugin fields, vendor plugin fields, etc.); the raw joomlatoken.token secret '
			. 'is redacted but its presence is reported.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'              => ['type' => 'integer'],
				'username'        => ['type' => 'string'],
				'email'           => ['type' => 'string'],
				'include_profile' => ['type' => 'boolean', 'description' => 'If true, include #__user_profiles rows in the response.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'name', 'username', 'email', 'block', 'sendEmail', 'registerDate', 'lastvisitDate', 'lastResetTime', 'resetCount', 'requireReset', 'authProvider', 'params']))
			->from($this->db->quoteName('#__users'));

		if (isset($arguments['id'])) {
			$query->where($this->db->quoteName('id') . ' = ' . (int) $arguments['id']);
		} elseif (!empty($arguments['username'])) {
			$query->where($this->db->quoteName('username') . ' = ' . $this->db->quote((string) $arguments['username']));
		} elseif (!empty($arguments['email'])) {
			$query->where($this->db->quoteName('email') . ' = ' . $this->db->quote((string) $arguments['email']));
		} else {
			return ToolResult::error('Provide id, username, or email.');
		}

		$user = $this->db->setQuery($query)->loadAssoc();
		if (!$user) {
			return ToolResult::error('User not found.');
		}

		$gq = $this->db->getQuery(true)
			->select([$this->db->quoteName('g.id'), $this->db->quoteName('g.title')])
			->from($this->db->quoteName('#__user_usergroup_map', 'm'))
			->innerJoin($this->db->quoteName('#__usergroups', 'g') . ' ON ' . $this->db->quoteName('g.id') . ' = ' . $this->db->quoteName('m.group_id'))
			->where($this->db->quoteName('m.user_id') . ' = ' . (int) $user['id']);
		$user['groups'] = $this->db->setQuery($gq)->loadAssocList() ?: [];

		if (!empty($arguments['include_profile'])) {
			$user['profile'] = $this->loadProfile((int) $user['id']);
		}

		return ToolResult::json($user);
	}

	/**
	 * Returns all #__user_profiles rows for the user, with the joomlatoken.token
	 * secret redacted but its presence preserved as a boolean. Other vendor
	 * plugin tokens (e.g. dpcalendar.private_token) are returned verbatim so
	 * the caller can spot them — they have no MCP-auth significance.
	 *
	 * @return array<string,mixed>
	 */
	private function loadProfile(int $userId): array
	{
		$q = $this->db->getQuery(true)
			->select([$this->db->quoteName('profile_key'), $this->db->quoteName('profile_value')])
			->from($this->db->quoteName('#__user_profiles'))
			->where($this->db->quoteName('user_id') . ' = ' . $userId)
			->order($this->db->quoteName('ordering') . ' ASC');
		$rows = $this->db->setQuery($q)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$key   = (string) $row['profile_key'];
			$value = (string) $row['profile_value'];

			if ($key === 'joomlatoken.token') {
				$out[$key] = $value !== '' ? '[redacted; present]' : '';
				continue;
			}
			$out[$key] = $value;
		}
		return $out;
	}
}
