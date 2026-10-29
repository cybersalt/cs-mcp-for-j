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
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * The User - Profile plugin's fields (address, phone, date of birth...), for
 * create_user, update_user and get_user (2.10.0, Tim's ruling on the field
 * review, 2026-10-28).
 *
 * Only when plg_user_profile is enabled, and only the fields its "Edit
 * Profile" settings leave on (profile-require_<field> > 0), which are the ones
 * Joomla shows on the user edit screen. The Terms of Service box is left out:
 * Joomla only uses it at registration.
 *
 * Saving goes through UserModel::save(): the plugin's onUserAfterSave deletes
 * and re-inserts only the keys in $data['profile'], so a patch of one field
 * leaves the others alone. Its onUserBeforeSave checks the date of birth.
 */
trait UserProfileTrait
{
	/** The profile fields, in the order of the plugin's form. */
	private static array $profileFields = [
		'address1', 'address2', 'city', 'region', 'country', 'postal_code',
		'phone', 'website', 'favoritebook', 'aboutme', 'dob',
	];

	private static function userProfileDescription(): string
	{
		return 'Fields from Joomla\'s User - Profile plugin, when that plugin is enabled: address1, address2, city, region, '
			. 'country, postal_code, phone, website, favoritebook, aboutme, dob (date of birth, YYYY-MM-DD). '
			. 'Only the fields the plugin shows on the user edit screen are accepted. Pass only the keys to change; '
			. 'null or "" clears a field.';
	}

	/** @return string[] The fields Joomla's user edit screen shows, or [] when the plugin is off. */
	private function userProfileFields(): array
	{
		if (!PluginHelper::isEnabled('user', 'profile')) {
			return [];
		}

		$plugin = PluginHelper::getPlugin('user', 'profile');
		$params = new Registry(is_object($plugin) ? ($plugin->params ?? '') : '');

		return array_values(array_filter(
			self::$profileFields,
			static fn (string $f): bool => (int) $params->get('profile-require_' . $f, 1) > 0
		));
	}

	/** @return array<string, string> */
	private function userProfilePatch(mixed $value): array
	{
		$fields = $this->userProfileFields();

		if ($fields === []) {
			throw new McpException(-32602, 'profile needs Joomla\'s "User - Profile" plugin, which is not enabled on this site.');
		}

		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new McpException(-32602, 'profile must be an object of profile fields and values; got ' . json_encode($value) . '.');
		}

		$out = [];

		foreach ($value as $key => $v) {
			$key = (string) $key;

			if (!in_array($key, $fields, true)) {
				throw new McpException(-32602, 'profile has no field "' . $key . '" on this site. Fields shown on the user edit screen: ' . implode(', ', $fields) . '.');
			}

			if ($v !== null && !is_scalar($v)) {
				throw new McpException(-32602, 'profile.' . $key . ' must be a single value; got ' . json_encode($v) . '.');
			}

			$s = trim((string) $v);

			if ($key === 'dob' && $s !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
				throw new McpException(-32602, 'profile.dob must be a date as YYYY-MM-DD; got ' . json_encode($v) . '.');
			}

			if ($key === 'website' && $s !== '' && !preg_match('#^https?://#i', $s)) {
				throw new McpException(-32602, 'profile.website must start with http:// or https://; got ' . json_encode($v) . '.');
			}

			$out[$key] = $s;
		}

		return $out;
	}

	/**
	 * The profile as stored, or null when the plugin is off.
	 *
	 * @return array<string, string>|null
	 */
	private function storedUserProfile(int $userId): ?array
	{
		$fields = $this->userProfileFields();

		if ($fields === []) {
			return null;
		}

		$db   = $this->db;
		$rows = (array) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['profile_key', 'profile_value']))
				->from($db->quoteName('#__user_profiles'))
				->where($db->quoteName('user_id') . ' = :id')
				->where($db->quoteName('profile_key') . ' LIKE ' . $db->quote('profile.%'))
				->bind(':id', $userId, ParameterType::INTEGER)
				->order($db->quoteName('ordering') . ' ASC')
		)->loadAssocList();

		$out = [];

		foreach ($rows as $row) {
			$key = substr((string) $row['profile_key'], 8);

			if (!in_array($key, $fields, true)) {
				continue;
			}

			$decoded   = json_decode((string) $row['profile_value'], true);
			$out[$key] = is_scalar($decoded) ? (string) $decoded : (string) $row['profile_value'];
		}

		return $out;
	}
}
