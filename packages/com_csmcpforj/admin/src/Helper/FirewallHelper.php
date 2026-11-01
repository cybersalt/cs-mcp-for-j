<?php

/**
 * @package     Cybersalt.MCPforJ
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Firewalls that block MCP and OAuth requests (GitHub #2).
 *
 * Field reports: RSFirewall answered every MCP call with "403 Access Forbidden
 * — Malware detected" (energizethemes.com, 2026-05-25), and on 2026-10-31 an
 * agency user's RSFirewall blocked the claude.ai sign-in until two exceptions
 * were added, while the host's WAF (o2switch TigerProtect) answered 503.
 *
 * detect() names the Joomla-side firewalls that are switched on, so the
 * Dashboard can show the exceptions for each. check() asks the site's own
 * public addresses the questions an AI app asks, and says which answers did
 * not come from MCP for J.
 */
final class FirewallHelper
{
	/**
	 * Product key => system plugin elements that do the blocking. The
	 * component alone blocks nothing, so only an enabled plugin counts.
	 */
	private const FIREWALLS = [
		'rsfirewall'    => ['rsfirewall'],
		'admintools'    => ['admintools'],
		'securitycheck' => ['securitycheck', 'securitycheckpro'],
	];

	/** @return string[] product keys of the firewalls switched on, e.g. ['rsfirewall'] */
	public static function detect(): array
	{
		$elements = array_merge(...array_values(self::FIREWALLS));

		try {
			$db    = Factory::getContainer()->get(DatabaseInterface::class);
			$found = $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('element'))
					->from($db->quoteName('#__extensions'))
					->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
					->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
					->where($db->quoteName('enabled') . ' = 1')
					->whereIn($db->quoteName('element'), $elements, ParameterType::STRING)
			)->loadColumn() ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$keys = [];

		foreach (self::FIREWALLS as $key => $plugins) {
			if (array_intersect($plugins, $found)) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Ask this site's public addresses what an AI app asks while connecting,
	 * from the web server itself. Nothing is changed and nothing lands in the
	 * activity log as a problem (selftest=1, and a revoke with no client is a
	 * no-op that RFC 7009 answers with 200).
	 *
	 * @return array<int, array{label: string, ok: bool, detail: string}>
	 */
	public static function check(bool $oauthEnabled): array
	{
		$root   = rtrim(Uri::root(), '/');
		$checks = [];

		$checks[] = self::probe(
			Text::_('COM_CSMCPFORJ_CHECK_ENDPOINT'),
			'POST',
			$root . '/api/index.php/v1/mcp',
			'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp-for-j-connection-check","version":"1"}}}',
			['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
			// Without credentials the endpoint answers 401; with OAuth on, the
			// 401 must point apps at the sign-in details.
			static fn (int $code, string $body, string $wwwAuth): bool => $code === 401
				&& (!$oauthEnabled || stripos($wwwAuth, 'resource_metadata=') !== false),
			'COM_CSMCPFORJ_CHECK_OK_ENDPOINT'
		);

		// While OAuth is off, the right answer from the sign-in addresses is
		// "switched off"; say so in words, not as a bare 404.
		$oauthOk = $oauthEnabled ? null : 'COM_CSMCPFORJ_CHECK_OK_OFF';

		$checks[] = self::probe(
			Text::_('COM_CSMCPFORJ_CHECK_DISCOVERY'),
			'GET',
			$root . '/.well-known/oauth-protected-resource?selftest=1',
			'',
			['Accept' => 'application/json'],
			static function (int $code, string $body) use ($oauthEnabled): bool {
				$json = json_decode($body, true);

				return $oauthEnabled
					? $code === 200 && !empty($json['authorization_servers'])
					: $code === 404 && ($json['error'] ?? '') === 'oauth_disabled';
			},
			$oauthOk ?? 'COM_CSMCPFORJ_CHECK_OK_DISCOVERY'
		);

		$checks[] = self::probe(
			Text::_('COM_CSMCPFORJ_CHECK_OAUTH_ENDPOINTS'),
			'POST',
			$root . '/index.php?csmcpforj_oauth=revoke&selftest=1',
			'token=connection-check',
			['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
			static function (int $code, string $body) use ($oauthEnabled): bool {
				$json = json_decode($body, true);

				return $oauthEnabled
					? $code === 200 && is_array($json)
					: $code === 404 && ($json['error'] ?? '') === 'oauth_disabled';
			},
			$oauthOk ?? 'COM_CSMCPFORJ_CHECK_OK_OAUTH'
		);

		return $checks;
	}

	/**
	 * @param callable(int, string, string): bool $expected
	 *
	 * @return array{label: string, ok: bool, detail: string}
	 */
	private static function probe(string $label, string $method, string $url, string $body, array $headers, callable $expected, string $okKey): array
	{
		$headers['User-Agent'] = 'MCP for J connection check';

		try {
			$http     = HttpFactory::getHttp(['follow_location' => false]);
			$response = $method === 'POST'
				? $http->post($url, $body, $headers, 10)
				: $http->get($url, $headers, 10);
		} catch (\Throwable $e) {
			return ['label' => $label, 'ok' => false, 'detail' => Text::sprintf('COM_CSMCPFORJ_CHECK_UNREACHABLE', mb_substr($e->getMessage(), 0, 200))];
		}

		$code     = $response->getStatusCode();
		$text     = (string) $response->getBody();
		$location = $response->getHeaderLine('Location');

		if ($expected($code, $text, $response->getHeaderLine('WWW-Authenticate'))) {
			return ['label' => $label, 'ok' => true, 'detail' => Text::_($okKey)];
		}

		if ($code >= 300 && $code < 400 && $location !== '') {
			return ['label' => $label, 'ok' => false, 'detail' => Text::sprintf('COM_CSMCPFORJ_CHECK_REDIRECT', $code, $location)];
		}

		// Show what answered instead: a firewall page names itself in the
		// first words more often than not.
		$snippet = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $text)) ?? ''));

		return ['label' => $label, 'ok' => false, 'detail' => Text::sprintf('COM_CSMCPFORJ_CHECK_UNEXPECTED', $code, mb_substr($snippet, 0, 160))];
	}
}
