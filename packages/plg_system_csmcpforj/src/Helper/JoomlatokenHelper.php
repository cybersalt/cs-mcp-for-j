<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Crypt\Crypt;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Helper for managing Joomla's per-user API tokens (plg_user_token).
 *
 * The Joomla 4+ token system stores three profile rows per user in
 * #__user_profiles, all keyed by 'joomlatoken.<key>':
 *
 *   joomlatoken.token   — base64(random_bytes(32)) seed
 *   joomlatoken.enabled — '1' or '0'
 *   joomlatoken.algorithm (optional) — 'sha256' (default)
 *
 * The display token format that an MCP client copies into its Authorization
 * header is:
 *
 *   base64( "<algo>:<user_id>:<hmac_hex>" )
 *
 * where hmac_hex = hash_hmac($algo, base64_decode($seed), $siteSecret).
 *
 * plg_user_token will only display the token to the user themselves. This
 * helper bypasses that check because cs-mcp-for-j tools are explicitly
 * Super-User-gated admin operations — the whole point of the four
 * *_user_api_token tools is programmatic per-user token management.
 */
final class JoomlatokenHelper
{
	private const PROFILE_PREFIX = 'joomlatoken';
	private const TOKEN_LENGTH   = 32;
	private const DEFAULT_ALGO   = 'sha256';

	public function __construct(private readonly DatabaseInterface $db) {}

	/**
	 * Returns the current state of a user's API token without revealing the seed.
	 *
	 * @return array{enabled: bool, has_secret: bool, algorithm: string, last_reset: ?string}
	 */
	public function status(int $userId): array
	{
		$rows = $this->loadProfileRows($userId);

		return [
			'enabled'    => (string) ($rows['enabled'] ?? '0') === '1',
			'has_secret' => !empty($rows['token']),
			'algorithm'  => (string) ($rows['algorithm'] ?? self::DEFAULT_ALGO),
			'last_reset' => $rows['_last_reset'] ?? null,
		];
	}

	/**
	 * Sets the enabled flag without touching the secret. Returns the new state.
	 */
	public function setEnabled(int $userId, bool $enabled): array
	{
		$this->upsertProfileRow($userId, 'enabled', $enabled ? '1' : '0');
		// Ensure algorithm row exists so plg_user_token's lookup matches.
		$rows = $this->loadProfileRows($userId);
		if (empty($rows['algorithm'])) {
			$this->upsertProfileRow($userId, 'algorithm', self::DEFAULT_ALGO);
		}
		return $this->status($userId);
	}

	/**
	 * Generates a fresh secret, enables the token, and returns the display
	 * string the user can paste into their MCP client. Also returns the new
	 * status block for convenience.
	 *
	 * @return array{display_token: string, status: array<string,mixed>}
	 */
	public function reset(int $userId): array
	{
		$seed = base64_encode(Crypt::genRandomBytes(self::TOKEN_LENGTH));
		$this->upsertProfileRow($userId, 'token', $seed);
		$this->upsertProfileRow($userId, 'enabled', '1');
		$this->upsertProfileRow($userId, 'algorithm', self::DEFAULT_ALGO);

		return [
			'display_token' => $this->displayToken($userId, $seed, self::DEFAULT_ALGO),
			'status'        => $this->status($userId),
		];
	}

	/**
	 * Disables the token and zeros the secret. Profile rows are kept (set to
	 * empty / '0') so a future reset() doesn't have to re-insert them.
	 */
	public function revoke(int $userId): array
	{
		$this->upsertProfileRow($userId, 'enabled', '0');
		$this->upsertProfileRow($userId, 'token', '');
		return $this->status($userId);
	}

	/**
	 * Computes the user-facing display token. Returns '' if the site secret is
	 * missing or the seed is empty.
	 */
	public function displayToken(int $userId, string $seed, string $algo = self::DEFAULT_ALGO): string
	{
		if ($seed === '') {
			return '';
		}
		$siteSecret = (string) Factory::getApplication()->get('secret', '');
		if ($siteSecret === '') {
			return '';
		}

		$raw  = base64_decode($seed);
		$hash = hash_hmac($algo, $raw, $siteSecret);
		return base64_encode("$algo:$userId:$hash");
	}

	/**
	 * @return array<string,string> profile_value indexed by the suffix after
	 *                              "joomlatoken." (e.g. 'token', 'enabled').
	 *                              Includes '_last_reset' synthesised from the
	 *                              most-recent ordering row if present.
	 */
	private function loadProfileRows(int $userId): array
	{
		// Assign before bind. Joomla's bind() is by-reference so the original
		// "assign after bind" pattern works at execute time, but ordering
		// matters for readability and prevents future refactors from
		// accidentally breaking the binding.
		$likePattern = self::PROFILE_PREFIX . '.%';

		$query = $this->db->getQuery(true)
			->select([$this->db->quoteName('profile_key'), $this->db->quoteName('profile_value')])
			->from($this->db->quoteName('#__user_profiles'))
			->where($this->db->quoteName('user_id') . ' = :userId')
			->where($this->db->quoteName('profile_key') . ' LIKE :keyPattern')
			->bind(':userId', $userId, ParameterType::INTEGER)
			->bind(':keyPattern', $likePattern, ParameterType::STRING);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$out = [];
		foreach ($rows as $row) {
			$suffix = substr((string) $row['profile_key'], strlen(self::PROFILE_PREFIX) + 1);
			$out[$suffix] = (string) $row['profile_value'];
		}
		return $out;
	}

	private function upsertProfileRow(int $userId, string $suffix, string $value): void
	{
		$key = self::PROFILE_PREFIX . '.' . $suffix;

		$exists = $this->db->getQuery(true)
			->select($this->db->quoteName('user_id'))
			->from($this->db->quoteName('#__user_profiles'))
			->where($this->db->quoteName('user_id') . ' = :userId')
			->where($this->db->quoteName('profile_key') . ' = :profileKey')
			->bind(':userId', $userId, ParameterType::INTEGER)
			->bind(':profileKey', $key, ParameterType::STRING);

		$hit = $this->db->setQuery($exists)->loadResult();

		if ($hit !== null) {
			$update = $this->db->getQuery(true)
				->update($this->db->quoteName('#__user_profiles'))
				->set($this->db->quoteName('profile_value') . ' = :profileValue')
				->where($this->db->quoteName('user_id') . ' = :userId')
				->where($this->db->quoteName('profile_key') . ' = :profileKey')
				->bind(':userId', $userId, ParameterType::INTEGER)
				->bind(':profileKey', $key, ParameterType::STRING)
				->bind(':profileValue', $value, ParameterType::STRING);
			$this->db->setQuery($update)->execute();
			return;
		}

		// Compute a stable ordering value relative to the highest existing one
		// for this user. plg_user_token doesn't strictly need this, but
		// #__user_profiles has an ordering column that defaults to 0.
		$nextOrder = $this->nextOrdering($userId);

		$insert = $this->db->getQuery(true)
			->insert($this->db->quoteName('#__user_profiles'))
			->columns($this->db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']))
			->values(implode(',', [
				(int) $userId,
				$this->db->quote($key),
				$this->db->quote($value),
				(int) $nextOrder,
			]));
		$this->db->setQuery($insert)->execute();
	}

	private function nextOrdering(int $userId): int
	{
		$q = $this->db->getQuery(true)
			->select('MAX(' . $this->db->quoteName('ordering') . ')')
			->from($this->db->quoteName('#__user_profiles'))
			->where($this->db->quoteName('user_id') . ' = :userId')
			->bind(':userId', $userId, ParameterType::INTEGER);
		return ((int) $this->db->setQuery($q)->loadResult()) + 1;
	}
}
