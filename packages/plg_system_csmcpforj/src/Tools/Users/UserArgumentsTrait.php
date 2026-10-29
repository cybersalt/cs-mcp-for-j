<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Rule\PasswordRule;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\User\User;

/**
 * Argument checks shared by create_user and update_user: group ids, the
 * password rules, and the Basic Settings / Accessibility Settings tabs of
 * Joomla 6.2's user edit screen (administrator/components/com_users/forms/
 * user.xml, <fields name="params">). Only the tools in this folder use it.
 *
 * The tools hand $data straight to com_users' UserModel::save(), which never
 * runs the edit form's validation. So everything the form would have checked
 * (the password rule, the option lists, which groups exist) is checked here.
 *
 * Needs $this->db and requireCanEditTargetUser(), so it can only be used by
 * an AbstractTool subclass.
 */
trait UserArgumentsTrait
{
	/**
	 * The params keys on Joomla 6.2's user edit screen, and the description
	 * shown to MCP clients. Kept in one place so create and update agree.
	 */
	private static function userParamsDescription(string $nullMeans): string
	{
		return 'User settings, as on the Basic Settings and Accessibility Settings tabs. '
			. 'Pass only the keys to change; ' . $nullMeans . ' '
			. 'Keys: admin_style (id of an administrator template style, see list_template_styles), '
			. 'admin_language (installed administrator language tag, e.g. "en-GB"), '
			. 'language (installed site language tag), '
			. 'editor (editor plugin element, e.g. "tinymce", "codemirror", "none"), '
			. 'timezone (PHP timezone id, e.g. "Europe/London"), '
			. 'colorScheme ("os", "light" or "dark"), '
			. 'allowTourAutoStart ("0" or "1"), '
			. 'a11y_mono ("monochrome" on, "0" off), a11y_contrast ("high_contrast" on, "0" off), '
			. 'a11y_highlight ("highlight" on, "0" off), a11y_font ("fontsize" on, "0" off); '
			. 'true/false are also accepted for the a11y_* keys. Other keys are refused.';
	}

	/**
	 * Validate a params patch from the caller.
	 *
	 * A null or "" value becomes "" — "Use Default" on the edit screen. That
	 * is deliberate rather than deleting the key: User::bind() merges the
	 * patch into the user's loaded params with Registry::loadArray(), which
	 * cannot remove a key, and Joomla itself stores "" for Use Default.
	 *
	 * @return array<string, int|string>
	 */
	private function userParamsPatch(mixed $value): array
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new McpException(-32602, 'params must be an object of setting keys and values; got ' . json_encode($value) . '.');
		}

		$a11y = [
			'a11y_mono'      => 'monochrome',
			'a11y_contrast'  => 'high_contrast',
			'a11y_highlight' => 'highlight',
			'a11y_font'      => 'fontsize',
		];

		$out = [];

		foreach ($value as $key => $v) {
			$key = (string) $key;

			if (isset($a11y[$key])) {
				$on = $a11y[$key];

				if ($v === null || $v === '') {
					$out[$key] = '0';
				} elseif ($v === true || $v === $on || $v === 1 || $v === '1') {
					$out[$key] = $on;
				} elseif ($v === false || $v === 0 || $v === '0') {
					$out[$key] = '0';
				} else {
					throw new McpException(-32602, 'params.' . $key . ' must be "' . $on . '" (on) or "0" (off); got ' . json_encode($v) . '.');
				}

				continue;
			}

			if ($v === null || $v === '') {
				if (!in_array($key, ['admin_style', 'colorScheme', 'allowTourAutoStart', 'admin_language', 'language', 'editor', 'timezone'], true)) {
					throw new McpException(-32602, 'params has no setting "' . $key . '".');
				}

				$out[$key] = '';
				continue;
			}

			if (!is_scalar($v)) {
				throw new McpException(-32602, 'params.' . $key . ' must be a single value; got ' . json_encode($v) . '.');
			}

			$s = trim((string) $v);

			switch ($key) {
				case 'admin_style':
					if (!ctype_digit($s) || (int) $s <= 0 || !$this->userParamExists('#__template_styles', ['id' => (int) $s, 'client_id' => 1])) {
						throw new McpException(-32602, 'params.admin_style must be the id of an administrator template style (list_template_styles); got ' . json_encode($v) . '.');
					}
					$out[$key] = (int) $s;
					break;

				case 'colorScheme':
					if (!in_array($s, ['os', 'light', 'dark'], true)) {
						throw new McpException(-32602, 'params.colorScheme must be "os", "light" or "dark" (null for the default); got ' . json_encode($v) . '.');
					}
					$out[$key] = $s;
					break;

				case 'allowTourAutoStart':
					if (is_bool($v)) {
						$s = $v ? '1' : '0';
					}
					if (!in_array($s, ['0', '1'], true)) {
						throw new McpException(-32602, 'params.allowTourAutoStart must be "0" or "1" (null for the default); got ' . json_encode($v) . '.');
					}
					$out[$key] = $s;
					break;

				case 'admin_language':
				case 'language':
					$clientId = $key === 'admin_language' ? 1 : 0;

					if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $s)
						|| !$this->userParamExists('#__extensions', ['type' => 'language', 'element' => $s, 'client_id' => $clientId, 'enabled' => 1])) {
						throw new McpException(-32602, 'params.' . $key . ' must be the tag of an installed, enabled '
							. ($clientId ? 'administrator' : 'site') . ' language (list_languages); got ' . json_encode($v) . '.');
					}

					// Same rule as the edit form: on a multilingual site the
					// frontend language must also be a published content language.
					if ($key === 'language' && Multilanguage::isEnabled()
						&& !$this->userParamExists('#__languages', ['lang_code' => $s, 'published' => 1])) {
						throw new McpException(-32602, 'params.language must be a published content language on this multilingual site (list_content_languages); got ' . json_encode($v) . '.');
					}
					$out[$key] = $s;
					break;

				case 'editor':
					if (!preg_match('/^[a-z0-9_\-]+$/i', $s)
						|| !$this->userParamExists('#__extensions', ['type' => 'plugin', 'folder' => 'editors', 'element' => $s, 'enabled' => 1])) {
						throw new McpException(-32602, 'params.editor must be the element of an enabled editor plugin (e.g. "tinymce", "codemirror", "none"); got ' . json_encode($v) . '.');
					}
					$out[$key] = $s;
					break;

				case 'timezone':
					if ($s !== 'UTC' && !in_array($s, \DateTimeZone::listIdentifiers(), true)) {
						throw new McpException(-32602, 'params.timezone must be a PHP timezone id such as "Europe/London" or "UTC"; got ' . json_encode($v) . '.');
					}
					$out[$key] = $s;
					break;

				default:
					throw new McpException(-32602, 'params has no setting "' . $key . '". Valid keys: admin_style, admin_language, language, editor, timezone, colorScheme, allowTourAutoStart, a11y_mono, a11y_contrast, a11y_highlight, a11y_font.');
			}
		}

		return $out;
	}

	/** @param array<string, int|string> $where */
	private function userParamExists(string $table, array $where): bool
	{
		$db = $this->db;
		$q  = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName($table));

		foreach ($where as $col => $val) {
			$q->where($db->quoteName($col) . ' = ' . (is_int($val) ? (string) $val : $db->quote($val)));
		}

		return (int) $db->setQuery($q)->loadResult() > 0;
	}

	/**
	 * Validate a groups argument: a non-empty list of ids of groups that
	 * exist. Table\User::bind() silently drops ids it cannot find, so a typo
	 * would otherwise "succeed" with a different group set than asked for.
	 *
	 * @return int[]
	 */
	private function userGroupIds(mixed $value): array
	{
		if (!is_array($value) || !array_is_list($value) || $value === []) {
			throw new McpException(-32602, 'groups must be a non-empty array of user group ids (list_user_groups). Joomla will not save a user with no groups.');
		}

		$ids = [];

		foreach ($value as $g) {
			if (!is_numeric($g) || (int) $g <= 0) {
				throw new McpException(-32602, 'groups must contain only positive group ids; got ' . json_encode($g) . '.');
			}
			$ids[] = (int) $g;
		}

		$ids = array_values(array_unique($ids));

		$db    = $this->db;
		$found = array_map('intval', (array) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName('#__usergroups'))
				->whereIn($db->quoteName('id'), $ids)
		)->loadColumn());

		$missing = array_values(array_diff($ids, $found));

		if ($missing !== []) {
			throw new McpException(-32602, 'No user group with id ' . implode(', ', $missing) . ' (list_user_groups).');
		}

		return $ids;
	}

	/**
	 * Apply the site's password rules (Users: Options > Password Options),
	 * using Joomla's own PasswordRule — the rule the edit form's password
	 * field runs. UserModel::save() does not run it, so without this an MCP
	 * caller could set a password the site's policy forbids. Our own floor of
	 * 12 characters still applies on top.
	 */
	private function checkUserPassword(string $password): void
	{
		if (strlen($password) < 12) {
			throw new McpException(-32602, 'password must be at least 12 characters.');
		}

		// PasswordRule only warns about this one; refuse it outright.
		if (strlen($password) > 4096) {
			throw new McpException(-32602, 'password must be at most 4096 characters.');
		}

		$app      = Factory::getApplication();
		$p        = ComponentHelper::getParams('com_users');
		$messages = [];

		try {
			// PasswordRule reports through the application message queue.
			// Set aside whatever is already queued so we read only its output.
			$queued = $app->getMessageQueue(true);
			$ok     = (new PasswordRule())->test(new \SimpleXMLElement('<field name="password" required="true" />'), $password);

			foreach ($app->getMessageQueue(true) as $m) {
				$messages[] = trim(strip_tags((string) ($m['message'] ?? '')));
			}

			foreach ($queued as $m) {
				$app->enqueueMessage((string) ($m['message'] ?? ''), (string) ($m['type'] ?? 'message'));
			}
		} catch (\Throwable) {
			// No usable message queue in this request: apply the same
			// com_users settings directly so the policy is never skipped.
			$ok = trim($password) === $password
				&& strlen($password) >= (int) ($p->get('minimum_length') ?: 12)
				&& preg_match_all('/[0-9]/', $password) >= (int) $p->get('minimum_integers', 0)
				&& preg_match_all('/\W/', $password) >= (int) $p->get('minimum_symbols', 0)
				&& preg_match_all('/[A-Z]/', $password) >= (int) $p->get('minimum_uppercase', 0)
				&& preg_match_all('/[a-z]/', $password) >= (int) $p->get('minimum_lowercase', 0);
		}

		if (!$ok) {
			throw new McpException(-32602, 'password does not meet this site\'s password rules'
				. ($messages ? ': ' . implode(' ', array_filter($messages)) : '.')
				. ' (Users > Options > Password Options: minimum_length ' . (int) $p->get('minimum_length', 12)
				. ', minimum_integers ' . (int) $p->get('minimum_integers', 0)
				. ', minimum_symbols ' . (int) $p->get('minimum_symbols', 0)
				. ', minimum_uppercase ' . (int) $p->get('minimum_uppercase', 0)
				. ', minimum_lowercase ' . (int) $p->get('minimum_lowercase', 0)
				. '; no leading or trailing spaces.)');
		}
	}

	/**
	 * Setting another user's password or email address is an account
	 * takeover if it is the wrong account (a new email lets the caller run
	 * "forgot password"). Joomla's User::save() only stops a non-Super-User
	 * touching a Super User, so these two fields additionally go through the
	 * same target-user guard the API-token tools use.
	 *
	 * @param string[] $fields
	 */
	private function requireCanChangeCredentials(User $actor, int $targetId, array $fields): void
	{
		if ((int) $actor->id === $targetId || $actor->authorise('core.admin')) {
			return;
		}

		try {
			$this->requireCanEditTargetUser($actor, $targetId);
		} catch (\Throwable $e) {
			throw new \RuntimeException(
				'Permission denied: you cannot change the ' . implode(' or ', $fields) . ' of user ' . $targetId
				. '. (' . $e->getMessage() . ')'
			);
		}
	}
}
