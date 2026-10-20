<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Log;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Utilities\IpHelper;

/**
 * The MCP for Joomla activity log: one row per event in #__csmcpforj_log,
 * shown under Components → MCP for Joomla → Logs.
 *
 * Added in 2.9.0 after Stefan Gagner's OAuth test failed with a bare
 * "server_error" and nothing anywhere on the site said why. Every OAuth step,
 * every token rejection (with the real reason), and every MCP tool call now
 * leaves a row, so "what just happened?" is answered from the admin instead
 * of the server's PHP error log.
 *
 * Rules every caller can rely on:
 *   - Logging never throws and never changes the outcome of a request. If the
 *     table is missing or the database refuses the write, the entry goes to
 *     PHP's error_log instead, and further writes in this request are skipped.
 *   - Callers never pass tokens, codes, client secrets or PKCE verifiers.
 *     redact() additionally masks secret-looking keys in tool arguments.
 *
 * Status values: success, info, warning, error. Sources: oauth (the
 * authorization server), auth (credentials arriving at the MCP endpoint),
 * mcp (JSON-RPC traffic and tool calls).
 */
final class ActivityLog
{
	public const TABLE = '#__csmcpforj_log';

	public const STATUSES = ['success', 'info', 'warning', 'error'];
	public const SOURCES  = ['oauth', 'auth', 'mcp'];

	/** Hard cap on rows kept, whatever the retention setting. */
	private const MAX_ROWS = 20000;

	/** Roughly one write in this many also purges old rows. */
	private const PURGE_ONE_IN = 50;

	private const MAX_SUMMARY = 500;
	private const MAX_DETAILS = 65000;

	private static ?string $requestId = null;
	private static bool $broken = false;

	/** Merged into every entry written during this request (e.g. the OAuth app). */
	private static array $requestContext = [];

	public static function success(string $source, string $event, string $summary, array $context = []): void
	{
		self::write('success', $source, $event, $summary, $context);
	}

	public static function info(string $source, string $event, string $summary, array $context = []): void
	{
		self::write('info', $source, $event, $summary, $context);
	}

	public static function warning(string $source, string $event, string $summary, array $context = []): void
	{
		self::write('warning', $source, $event, $summary, $context);
	}

	public static function error(string $source, string $event, string $summary, array $context = []): void
	{
		self::write('error', $source, $event, $summary, $context);
	}

	/** An error entry carrying the exception's class, message, location and trace. */
	public static function exception(string $source, string $event, \Throwable $e, array $context = []): void
	{
		$context['details'] = array_merge(
			is_array($context['details'] ?? null) ? $context['details'] : [],
			['exception' => self::describeThrowable($e)]
		);

		self::write('error', $source, $event, get_class($e) . ': ' . $e->getMessage(), $context);
	}

	/**
	 * Context that applies to every later entry in this request, e.g. which
	 * OAuth app a token belongs to once the system plugin has validated it.
	 */
	public static function setRequestContext(array $context): void
	{
		self::$requestContext = array_merge(self::$requestContext, $context);
	}

	/** 'all' (default), 'problems' (warnings and errors only) or 'off'. */
	public static function level(): string
	{
		try {
			$level = (string) ComponentHelper::getParams('com_csmcpforj')->get('log_level', 'all');
		} catch (\Throwable $e) {
			$level = 'all';
		}

		return in_array($level, ['all', 'problems', 'off'], true) ? $level : 'all';
	}

	/**
	 * Copy of tool arguments that is safe to store: secret-looking keys are
	 * masked, long strings shortened, deep or long structures cut off.
	 */
	public static function redact(mixed $value, int $depth = 0): mixed
	{
		if (is_string($value)) {
			$len = mb_strlen($value);

			return $len > 300 ? mb_substr($value, 0, 300) . '… (' . $len . ' chars)' : $value;
		}

		if (!is_array($value)) {
			return $value;
		}

		if ($depth >= 4) {
			return '[… nested ' . count($value) . ' items]';
		}

		$out = [];
		$i   = 0;

		foreach ($value as $key => $item) {
			if (++$i > 50) {
				$out['…'] = (count($value) - 50) . ' more items';
				break;
			}

			$out[$key] = is_string($key) && preg_match('/pass(word)?|secret|token|api[_-]?key|dlid|credential|private[_-]?key/i', $key)
				? '[redacted]'
				: self::redact($item, $depth + 1);
		}

		return $out;
	}

	public static function describeThrowable(\Throwable $e): array
	{
		$root = defined('JPATH_ROOT') ? JPATH_ROOT : '';
		$rel  = static fn (string $path): string => $root !== '' && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

		$out = [
			'class'   => get_class($e),
			'message' => $e->getMessage(),
			'code'    => $e->getCode(),
			'at'      => $rel($e->getFile()) . ':' . $e->getLine(),
			'trace'   => mb_substr(str_replace($root, '', $e->getTraceAsString()), 0, 8000),
		];

		if ($e->getPrevious() !== null) {
			$out['previous'] = get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage()
				. ' at ' . $rel($e->getPrevious()->getFile()) . ':' . $e->getPrevious()->getLine();
		}

		return $out;
	}

	private static function write(string $status, string $source, string $event, string $summary, array $context): void
	{
		if (self::$broken) {
			return;
		}

		$level = self::level();

		if ($level === 'off' || ($level === 'problems' && !in_array($status, ['warning', 'error'], true))) {
			return;
		}

		$context = array_merge(self::$requestContext, $context);
		$details = $context['details'] ?? null;

		if ($source === 'oauth' || $source === 'auth') {
			$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

			if ($ua !== '') {
				$details = (is_array($details) ? $details : ($details === null ? [] : ['note' => $details]))
					+ ['user_agent' => mb_substr($ua, 0, 200)];
			}
		}

		if (is_array($details)) {
			$details = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		}

		$row = (object) [
			'created'    => gmdate('Y-m-d H:i:s'),
			'status'     => $status,
			'source'     => substr($source, 0, 16),
			'event'      => substr($event, 0, 64),
			'subject'    => mb_substr((string) ($context['subject'] ?? ''), 0, 191),
			'summary'    => mb_substr($summary, 0, self::MAX_SUMMARY),
			'details'    => $details === null ? '' : mb_substr((string) $details, 0, self::MAX_DETAILS),
			'user_id'    => (int) ($context['user_id'] ?? 0),
			'client_id'  => substr((string) ($context['client_id'] ?? ''), 0, 64),
			'ip'         => self::ip(),
			'request_id' => self::requestId(),
			'elapsed_ms' => isset($context['elapsed_ms']) ? (int) $context['elapsed_ms'] : null,
		];

		try {
			$db = Factory::getContainer()->get(DatabaseInterface::class);

			// $row is a variable on purpose: insertObject() takes it by
			// reference, and a cast literal there is a fatal Error in PHP 8
			// (the 2.9.0 OAuth bug this log was built to expose).
			$db->insertObject(self::TABLE, $row);

			if (random_int(1, self::PURGE_ONE_IN) === 1) {
				self::purge($db);
			}
		} catch (\Throwable $e) {
			self::$broken = true;
			error_log('[MCP for Joomla] ' . strtoupper($status) . ' ' . $event . ': ' . $summary
				. ' (activity log unavailable: ' . $e->getMessage() . ')');
		}
	}

	/** Delete rows past the retention period, and anything beyond MAX_ROWS. */
	public static function purge(?DatabaseInterface $db = null): void
	{
		$db ??= Factory::getContainer()->get(DatabaseInterface::class);

		$days = (int) ComponentHelper::getParams('com_csmcpforj')->get('log_retention_days', 30);
		$days = max(1, min($days, 365));

		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName(self::TABLE))
				->where($db->quoteName('created') . ' < ' . $db->quote(gmdate('Y-m-d H:i:s', time() - $days * 86400)))
		)->execute();

		$cutoff = (int) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('id'))
				->from($db->quoteName(self::TABLE))
				->order($db->quoteName('id') . ' DESC')
				->setLimit(1, self::MAX_ROWS)
		)->loadResult();

		if ($cutoff > 0) {
			$db->setQuery(
				$db->getQuery(true)
					->delete($db->quoteName(self::TABLE))
					->where($db->quoteName('id') . ' <= ' . $cutoff)
			)->execute();
		}
	}

	public static function ensureSchema(DatabaseInterface $db): void
	{
		if (stripos($db->getServerType(), 'mysql') === false) {
			// Same as the OAuth tables: MySQL/MariaDB DDL only. On PostgreSQL
			// write() falls back to PHP's error_log.
			return;
		}

		$db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::TABLE) . ' (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`created` datetime NOT NULL,
			`status` varchar(10) NOT NULL,
			`source` varchar(16) NOT NULL,
			`event` varchar(64) NOT NULL,
			`subject` varchar(191) NOT NULL DEFAULT \'\',
			`summary` varchar(500) NOT NULL DEFAULT \'\',
			`details` mediumtext NOT NULL,
			`user_id` int NOT NULL DEFAULT 0,
			`client_id` varchar(64) NOT NULL DEFAULT \'\',
			`ip` varchar(45) NOT NULL DEFAULT \'\',
			`request_id` varchar(16) NOT NULL DEFAULT \'\',
			`elapsed_ms` int NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `idx_created` (`created`),
			KEY `idx_status` (`status`, `created`),
			KEY `idx_source` (`source`, `created`),
			KEY `idx_request` (`request_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')->execute();
	}

	public static function dropSchema(DatabaseInterface $db): void
	{
		$db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName(self::TABLE))->execute();
	}

	private static function requestId(): string
	{
		return self::$requestId ??= bin2hex(random_bytes(6));
	}

	private static function ip(): string
	{
		try {
			return substr((string) IpHelper::getIp(), 0, 45);
		} catch (\Throwable $e) {
			return '';
		}
	}
}
