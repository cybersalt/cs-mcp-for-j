<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Model;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * The Logs list (Joomla-Brain "Standard Log Viewer": stats bar, filters,
 * Dump / CSV / Delete / Clear All). Modelled on cs-cron-master's LogsModel.
 */
final class LogsModel extends ListModel
{
	public function __construct($config = [])
	{
		if (empty($config['filter_fields'])) {
			$config['filter_fields'] = [
				'id', 'a.id',
				'created', 'a.created',
				'status', 'a.status',
				'source', 'a.source',
				'event', 'a.event',
				'subject', 'a.subject',
				'elapsed_ms', 'a.elapsed_ms',
				'username', 'u.username',
				'since', 'request_id', 'user_id',
			];
		}

		parent::__construct($config);
	}

	/** Filters arrive as filter[...] (searchtools and the stats-card links). */
	protected function populateState($ordering = 'a.id', $direction = 'DESC'): void
	{
		parent::populateState($ordering, $direction);
	}

	protected function getStoreId($id = ''): string
	{
		foreach (['search', 'status', 'source', 'event', 'since', 'request_id', 'user_id'] as $f) {
			$id .= ':' . $this->getState('filter.' . $f);
		}

		return parent::getStoreId($id);
	}

	/**
	 * Whole-table counts for the stats bar (not narrowed by the active
	 * filters, so "3 errors" stays visible while looking at the last hour).
	 */
	public function getStats(): array
	{
		$db     = $this->getDatabase();
		$cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
		$sum    = static fn (string $col, string $val): string => 'SUM(CASE WHEN ' . $db->quoteName($col) . ' = ' . $db->quote($val) . ' THEN 1 ELSE 0 END)';

		try {
			$row = $db->setQuery(
				$db->getQuery(true)
					->select([
						'COUNT(*) AS total',
						'SUM(CASE WHEN ' . $db->quoteName('created') . ' >= ' . $db->quote($cutoff) . ' THEN 1 ELSE 0 END) AS last24h',
						$sum('status', 'success') . ' AS success',
						$sum('status', 'info') . ' AS info',
						$sum('status', 'warning') . ' AS warning',
						$sum('status', 'error') . ' AS error',
						$sum('source', 'oauth') . ' AS oauth',
						$sum('event', 'tools/call') . ' AS toolcalls',
					])
					->from($db->quoteName(ActivityLog::TABLE))
			)->loadAssoc() ?: [];
		} catch (\Throwable $e) {
			$row = [];
		}

		$out = [];

		foreach (['total', 'last24h', 'success', 'info', 'warning', 'error', 'oauth', 'toolcalls'] as $k) {
			$out[$k] = (int) ($row[$k] ?? 0);
		}

		return $out;
	}

	/** False when the table is missing (package postflight never ran). */
	public function tableExists(): bool
	{
		$db = $this->getDatabase();

		try {
			return in_array($db->replacePrefix(ActivityLog::TABLE), $db->getTableList(), true);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/** Every row matching the current filters, ignoring pagination. */
	private function getAllFiltered(): array
	{
		return $this->getDatabase()->setQuery($this->getListQuery(), 0, 5000)->loadObjectList() ?: [];
	}

	public function getCsv(): string
	{
		$rows   = [self::csvRow(['id', 'created_utc', 'status', 'source', 'event', 'subject', 'summary', 'user', 'client_id', 'ip', 'request_id', 'elapsed_ms', 'details'])];

		foreach ($this->getAllFiltered() as $item) {
			$rows[] = self::csvRow([
				(string) $item->id,
				(string) $item->created,
				(string) $item->status,
				(string) $item->source,
				(string) $item->event,
				(string) $item->subject,
				(string) $item->summary,
				(string) ($item->username ?? ($item->user_id ? '#' . $item->user_id : '')),
				(string) $item->client_id,
				(string) $item->ip,
				(string) $item->request_id,
				$item->elapsed_ms === null ? '' : (string) $item->elapsed_ms,
				(string) $item->details,
			]);
		}

		return implode("\r\n", $rows) . "\r\n";
	}

	/** Plain text for pasting into a support email or ticket. */
	public function getTextDump(?array $items = null, string $heading = 'MCP for Joomla - Activity Log'): string
	{
		$items ??= $this->getAllFiltered();
		$stats   = $this->getStats();

		$out  = $heading . "\n";
		$out .= 'Generated: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
		$out .= sprintf(
			"Totals: %d entries (last 24h: %d) - success: %d / info: %d / warning: %d / error: %d\n",
			$stats['total'], $stats['last24h'], $stats['success'], $stats['info'], $stats['warning'], $stats['error']
		);
		$out .= 'Rows in this dump: ' . count($items) . "\n";
		$out .= str_repeat('=', 78) . "\n\n";

		foreach ($items as $item) {
			$out .= sprintf("#%d  [%s]  %s  %s UTC\n", (int) $item->id, strtoupper((string) $item->status), (string) $item->event, (string) $item->created);
			$out .= 'Summary:  ' . (string) $item->summary . "\n";

			if ((string) $item->subject !== '') {
				$out .= 'Subject:  ' . (string) $item->subject . "\n";
			}

			$who = (string) ($item->username ?? '');
			$out .= 'Source:   ' . (string) $item->source
				. ($who !== '' ? '   User: ' . $who : ((int) $item->user_id ? '   User: #' . (int) $item->user_id : ''))
				. ((string) $item->client_id !== '' ? '   App: ' . (string) $item->client_id : '')
				. '   Request: ' . (string) $item->request_id
				. ($item->elapsed_ms !== null ? '   ' . (int) $item->elapsed_ms . ' ms' : '') . "\n";

			if ((string) $item->details !== '') {
				$out .= "Details:\n" . rtrim((string) $item->details, "\n") . "\n";
			}

			$out .= str_repeat('-', 78) . "\n";
		}

		return $out;
	}

	/**
	 * Recent warnings and errors, for attaching to a Support form bug report.
	 *
	 * @return object[]
	 */
	public function getRecentProblems(int $limit = 25, int $days = 7): array
	{
		$db = $this->getDatabase();

		try {
			return $db->setQuery(
				$this->baseQuery()
					->whereIn($db->quoteName('a.status'), ['warning', 'error'], ParameterType::STRING)
					->where($db->quoteName('a.created') . ' >= ' . $db->quote(gmdate('Y-m-d H:i:s', time() - $days * 86400)))
					->order($db->quoteName('a.id') . ' DESC'),
				0,
				$limit
			)->loadObjectList() ?: [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	private static function csvRow(array $fields): string
	{
		return implode(',', array_map(static function (string $f): string {
			$f = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $f) ?? '';

			// Spreadsheet formula injection: a cell starting with = + - @ runs.
			if ($f !== '' && strpbrk($f[0], '=+-@') !== false) {
				$f = "'" . $f;
			}

			return '"' . str_replace('"', '""', $f) . '"';
		}, $fields));
	}

	private function baseQuery(): QueryInterface
	{
		$db = $this->getDatabase();

		return $db->getQuery(true)
			->select([$db->quoteName('a') . '.*', $db->quoteName('u.username'), $db->quoteName('u.name', 'user_name')])
			->from($db->quoteName(ActivityLog::TABLE, 'a'))
			->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('a.user_id'));
	}

	protected function getListQuery(): QueryInterface
	{
		$db    = $this->getDatabase();
		$query = $this->baseQuery();

		$search = trim((string) $this->getState('filter.search'));

		if ($search !== '') {
			if (stripos($search, 'id:') === 0) {
				$query->where($db->quoteName('a.id') . ' = ' . (int) substr($search, 3));
			} else {
				$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
				$query->where('(' . implode(' OR ', [
					$db->quoteName('a.summary') . ' LIKE :s1',
					$db->quoteName('a.subject') . ' LIKE :s2',
					$db->quoteName('a.details') . ' LIKE :s3',
					$db->quoteName('u.username') . ' LIKE :s4',
					$db->quoteName('a.ip') . ' LIKE :s5',
				]) . ')')
					->bind([':s1', ':s2', ':s3', ':s4', ':s5'], $like);
			}
		}

		$status = (string) $this->getState('filter.status');

		if (in_array($status, ActivityLog::STATUSES, true)) {
			$query->where($db->quoteName('a.status') . ' = :status')->bind(':status', $status);
		} elseif ($status === 'problems') {
			$query->whereIn($db->quoteName('a.status'), ['warning', 'error'], ParameterType::STRING);
		}

		$source = (string) $this->getState('filter.source');

		if (in_array($source, ActivityLog::SOURCES, true)) {
			$query->where($db->quoteName('a.source') . ' = :source')->bind(':source', $source);
		}

		$event = (string) $this->getState('filter.event');

		if ($event !== '') {
			$query->where($db->quoteName('a.event') . ' = :event')->bind(':event', $event);
		}

		$requestId = (string) $this->getState('filter.request_id');

		if ($requestId !== '' && preg_match('/^[a-f0-9]{1,16}$/', $requestId)) {
			$query->where($db->quoteName('a.request_id') . ' = :rid')->bind(':rid', $requestId);
		}

		$userId = (int) $this->getState('filter.user_id');

		if ($userId > 0) {
			$query->where($db->quoteName('a.user_id') . ' = :uid')->bind(':uid', $userId, ParameterType::INTEGER);
		}

		$seconds = match ((string) $this->getState('filter.since')) {
			'1h'    => 3600,
			'24h'   => 86400,
			'7d'    => 7 * 86400,
			'30d'   => 30 * 86400,
			default => 0,
		};

		if ($seconds > 0) {
			$since = gmdate('Y-m-d H:i:s', time() - $seconds);
			$query->where($db->quoteName('a.created') . ' >= :since')->bind(':since', $since);
		}

		$ordering  = (string) $this->getState('list.ordering', 'a.id');
		$direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

		if (!in_array($ordering, $this->filter_fields, true)) {
			$ordering = 'a.id';
		}

		$query->order($db->escape($ordering) . ' ' . $direction);

		return $query;
	}
}
