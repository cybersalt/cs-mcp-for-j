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

final class GetUserTool extends AbstractTool
{
	use UsersAclTrait;
	use UserProfileTrait;

	public function getName(): string { return 'get_user'; }

	public function getDescription(): string
	{
		return 'Fetch a single user by id (or by username or email). Returns the Account Details fields '
			. '(name, username, email, block, sendEmail, requireReset, registerDate, lastvisitDate, lastResetTime, '
			. 'resetCount, authProvider), activated (false while an activation link is outstanding), group '
			. 'memberships, params (Basic and Accessibility Settings, decoded) and mfa (the Multi-factor '
			. 'Authentication methods set up: method, title, default, dates — never their secrets), and profile '
			. '(the User - Profile plugin\'s fields, when that plugin is enabled). Your own account is always readable; '
			. 'anyone else\'s needs Access Administration Interface on Users, as in the Joomla admin. '
			. 'Password hash, otpKey, otep, MFA options and activation tokens are NEVER returned. '
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

		// Anyone may read their own account. Anyone else's needs what Joomla's
		// Users screen needs (Access Administration Interface on Users). Checked
		// before "not found" so the tool cannot be used to probe for accounts.
		if (!$user || (int) $user['id'] !== (int) $actor->id) {
			$this->requireUsersRight($actor, 'core.manage', 'looking up another user');
		}

		if (!$user) {
			return ToolResult::error('User not found.');
		}

		foreach (['id', 'block', 'sendEmail', 'resetCount', 'requireReset'] as $intKey) {
			$user[$intKey] = (int) $user[$intKey];
		}

		// Decoded, so it reads the same shape update_user's params patch takes.
		$params         = json_decode((string) $user['params'], true);
		$user['params'] = (object) (is_array($params) ? $params : []);

		// Only whether an activation is pending — never the code itself.
		$user['activated'] = (string) $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('activation'))
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $user['id'])
		)->loadResult() === '';

		$gq = $this->db->getQuery(true)
			->select([$this->db->quoteName('g.id'), $this->db->quoteName('g.title')])
			->from($this->db->quoteName('#__user_usergroup_map', 'm'))
			->innerJoin($this->db->quoteName('#__usergroups', 'g') . ' ON ' . $this->db->quoteName('g.id') . ' = ' . $this->db->quoteName('m.group_id'))
			->where($this->db->quoteName('m.user_id') . ' = ' . (int) $user['id']);
		$user['groups'] = $this->db->setQuery($gq)->loadAssocList() ?: [];

		$user['mfa'] = $this->loadMfaMethods($user['id']);

		if (!empty($arguments['include_profile'])) {
			$user['profile'] = $this->loadProfile((int) $user['id']);
		}

		// The User - Profile plugin's fields, when that plugin is enabled.
		$profile = $this->storedUserProfile((int) $user['id']);
		if ($profile !== null) {
			$user['profile'] = (object) $profile;
		}

		return ToolResult::json($user);
	}

	/**
	 * The Multi-factor Authentication methods on the user's MFA tab, without
	 * the `options` column — that holds the (encrypted) TOTP secret, backup
	 * codes, WebAuthn keys and so on. Retry counters are left out too.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadMfaMethods(int $userId): array
	{
		try {
			$rows = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName(['id', 'method', 'title', 'default', 'created_on', 'last_used']))
					->from($this->db->quoteName('#__user_mfa'))
					->where($this->db->quoteName('user_id') . ' = ' . $userId)
					->order($this->db->quoteName('id') . ' ASC')
			)->loadAssocList() ?: [];
		} catch (\Throwable) {
			// No #__user_mfa table (pre-4.2 schema): nothing set up.
			return [];
		}

		return array_map(static fn (array $r): array => [
			'id'         => (int) $r['id'],
			'method'     => (string) $r['method'],
			'title'      => (string) $r['title'],
			'default'    => (bool) $r['default'],
			'created_on' => $r['created_on'],
			'last_used'  => $r['last_used'],
		], $rows);
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
