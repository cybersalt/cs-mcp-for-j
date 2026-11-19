<?php

/**
 * @package     Cybersalt.MCPforJ
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * "Allowed, then nothing" (GitHub #60).
 *
 * Field report, Nov 2026: ChatGPT got through discovery, the user clicked
 * Allow (oauth.authorize, success), then ChatGPT said "OAuth token request
 * failed" and the log had no oauth.token line at all. Every outcome of the
 * token endpoint writes a line (success, each refusal, exceptions), so no
 * line means the app's server-to-server request never reached MCP for J:
 * a host WAF, a system plugin that runs before ours, or the app itself.
 * This finds those sign-ins so the Logs page and the Dashboard can say so.
 */
final class OAuthDiagnostics
{
	/** Apps ask for their token within seconds of Allow; wait this long before calling it missing. */
	private const GRACE_SECONDS = 120;

	/** Authorization codes live 10 minutes (OAuthServer::CODE_TTL_SECONDS); a later attempt is still logged, as a refusal. */
	private const WINDOW_SECONDS = 600;

	/** Apps checked per call, newest first. */
	private const MAX_APPS = 10;

	/** Display names for FirewallHelper::detect() keys. Product names, not translated. */
	private const FIREWALL_NAMES = [
		'rsfirewall'    => 'RSFirewall',
		'admintools'    => 'Admin Tools',
		'securitycheck' => 'Securitycheck',
		'route66'       => 'Route 66',
	];

	/**
	 * The newest "allowed" sign-in per app in the last $days that no token
	 * request followed. Only the newest one per app counts, so the warning
	 * goes away once the app connects.
	 *
	 * @return object[] log rows: id, created (UTC), client_id, subject (app name), user_id, username
	 */
	public static function unclaimedAuthorizations(int $days = 7): array
	{
		try {
			$db   = Factory::getContainer()->get(DatabaseInterface::class);
			$rows = $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName(['a.id', 'a.created', 'a.client_id', 'a.subject', 'a.user_id', 'u.username']))
					->from($db->quoteName(ActivityLog::TABLE, 'a'))
					->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.user_id'))
					->where($db->quoteName('a.source') . ' = ' . $db->quote('oauth'))
					->where($db->quoteName('a.event') . ' = ' . $db->quote('oauth.authorize'))
					->where($db->quoteName('a.status') . ' = ' . $db->quote('success'))
					->where($db->quoteName('a.client_id') . ' <> ' . $db->quote(''))
					->where($db->quoteName('a.created') . ' >= ' . $db->quote(gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)))
					->order($db->quoteName('a.id') . ' DESC'),
				0,
				200
			)->loadObjectList() ?: [];

			$latest = [];

			foreach ($rows as $row) {
				if (!isset($latest[$row->client_id]) && count($latest) < self::MAX_APPS) {
					$latest[$row->client_id] = $row;
				}
			}

			$out = [];

			foreach ($latest as $clientId => $row) {
				$allowedAt = strtotime($row->created . ' UTC');

				if ($allowedAt === false || $allowedAt > time() - self::GRACE_SECONDS) {
					continue;
				}

				// A token line with no app on it (refused before the app was
				// identified, or an exception) still proves the request arrived.
				$clientIds = [(string) $clientId, ''];
				$followed  = (int) $db->setQuery(
					$db->getQuery(true)
						->select('COUNT(*)')
						->from($db->quoteName(ActivityLog::TABLE))
						->where($db->quoteName('source') . ' = ' . $db->quote('oauth'))
						->where($db->quoteName('event') . ' = ' . $db->quote('oauth.token'))
						->whereIn($db->quoteName('client_id'), $clientIds, ParameterType::STRING)
						->where($db->quoteName('created') . ' >= ' . $db->quote($row->created))
						->where($db->quoteName('created') . ' <= ' . $db->quote(gmdate('Y-m-d H:i:s', $allowedAt + self::WINDOW_SECONDS)))
				)->loadResult();

				if ($followed === 0) {
					$out[] = $row;
				}
			}

			return $out;
		} catch (\Throwable $e) {
			// No log table yet (PostgreSQL, postflight not run): nothing to say.
			return [];
		}
	}

	/**
	 * The warning shown on the Logs page and the Dashboard. $boxClass is the
	 * page's own callout style; $logsUrl adds a "show it in the Logs" link.
	 *
	 * @param object[] $rows from unclaimedAuthorizations()
	 */
	public static function renderNotice(array $rows, string $boxClass, string $logsUrl = ''): string
	{
		if ($rows === []) {
			return '';
		}

		$e     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$items = '';

		foreach ($rows as $row) {
			$who    = (string) ($row->username ?? '') !== '' ? (string) $row->username : ((int) $row->user_id > 0 ? '#' . (int) $row->user_id : '?');
			$items .= '<li>' . Text::sprintf(
				'COM_CSMCPFORJ_OAUTH_UNCLAIMED_ITEM',
				$e((string) ($row->subject !== '' ? $row->subject : $row->client_id)),
				$e($who),
				HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC2'))
			) . '</li>';
		}

		$firewalls = self::firewallNames();

		return '<div class="' . $e($boxClass) . '">'
			. '<strong class="csmcpforj-callout-title">' . Text::_('COM_CSMCPFORJ_OAUTH_UNCLAIMED_TITLE') . '</strong>'
			. '<ul class="mb-2 mt-1">' . $items . '</ul>'
			. '<p class="mb-0">' . Text::_('COM_CSMCPFORJ_OAUTH_UNCLAIMED_BODY')
			. ($firewalls !== '' ? ' ' . Text::sprintf('COM_CSMCPFORJ_OAUTH_UNCLAIMED_FIREWALLS', $e($firewalls)) : '')
			. ($logsUrl !== '' ? ' <a href="' . $e($logsUrl) . '">' . Text::_('COM_CSMCPFORJ_OAUTH_UNCLAIMED_LOGS_LINK') . '</a>' : '')
			. '</p></div>';
	}

	/** e.g. "RSFirewall, Admin Tools", or '' when none of the known ones is switched on. */
	public static function firewallNames(): string
	{
		return implode(', ', array_map(
			static fn (string $key): string => self::FIREWALL_NAMES[$key] ?? $key,
			FirewallHelper::detect()
		));
	}
}
