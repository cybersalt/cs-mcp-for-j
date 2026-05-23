<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * List RSTicketsPro tickets with the most-useful filters surfaced as
 * top-level args. Joins to departments / statuses / priorities /
 * customer users / staff users so the agent gets human-readable labels
 * alongside the FK columns.
 *
 * Default ordering matches the admin UI: most-recently-replied first.
 */
final class ListTicketsTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_tickets'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro tickets (#__rsticketspro_tickets). Filters: status_id (1=open, '
			. '2=closed, 3=on-hold — can be array), department_id (array), priority_id (array), '
			. 'staff_id (single, 0=unassigned — JOOMLA USER ID, NOT _staff PK), customer_id, '
			. 'last_reply_customer (1=customer was last to reply, 0=we were), flagged, search '
			. '(matches code/subject), date_from / date_to (last_reply window, YYYY-MM-DD). '
			. 'Default limit 50, max 200. Ordering defaults to last_reply DESC. Returns id, code, '
			. 'subject, status, department, priority, staff (the joined user_name/_email — '
			. 'tickets.staff_id is a Joomla user_id despite the column name), customer, '
			. 'last_reply, last_reply_customer, replies, flagged, date, closed. Use get_rst_ticket '
			. 'for full details of one row.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'status_id'            => ['oneOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'integer']]]],
				'department_id'        => ['oneOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'integer']]]],
				'priority_id'          => ['oneOf' => [['type' => 'integer'], ['type' => 'array', 'items' => ['type' => 'integer']]]],
				'staff_id'             => ['type' => 'integer'],
				'customer_id'          => ['type' => 'integer'],
				'last_reply_customer'  => ['type' => 'integer', 'enum' => [0, 1]],
				'flagged'              => ['type' => 'integer', 'enum' => [0, 1]],
				'search'               => ['type' => 'string', 'description' => 'Matches code OR subject (LIKE %term%).'],
				'date_from'            => ['type' => 'string', 'description' => 'last_reply >= this (YYYY-MM-DD).'],
				'date_to'              => ['type' => 'string', 'description' => 'last_reply <= this (YYYY-MM-DD).'],
				'order_by'             => ['type' => 'string', 'enum' => ['last_reply', 'date', 'id', 'code', 'subject', 'replies'], 'description' => 'Default last_reply.'],
				'order_dir'            => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default DESC.'],
				'limit'                => ['type' => 'integer', 'description' => 'Default 50, max 200.'],
				'offset'               => ['type' => 'integer', 'description' => 'Default 0.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$prefix = $this->db->getPrefix();
		$tT  = $prefix . 'rsticketspro_tickets';
		$tD  = $prefix . 'rsticketspro_departments';
		$tSt = $prefix . 'rsticketspro_statuses';
		$tP  = $prefix . 'rsticketspro_priorities';
		$tU  = $prefix . 'users';

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('t.id'),
				$this->db->quoteName('t.code'),
				$this->db->quoteName('t.subject'),
				$this->db->quoteName('t.status_id'),
				$this->db->quoteName('s.name', 'status'),
				$this->db->quoteName('t.department_id'),
				$this->db->quoteName('d.name', 'department'),
				$this->db->quoteName('t.priority_id'),
				$this->db->quoteName('p.name', 'priority'),
				$this->db->quoteName('t.staff_id'),
				$this->db->quoteName('su.name', 'staff_name'),
				$this->db->quoteName('su.email', 'staff_email'),
				$this->db->quoteName('t.customer_id'),
				$this->db->quoteName('cu.name', 'customer_name'),
				$this->db->quoteName('cu.email', 'customer_email'),
				$this->db->quoteName('t.alternative_email'),
				$this->db->quoteName('t.date'),
				$this->db->quoteName('t.last_reply'),
				$this->db->quoteName('t.last_reply_customer'),
				$this->db->quoteName('t.replies'),
				$this->db->quoteName('t.flagged'),
				$this->db->quoteName('t.closed'),
				$this->db->quoteName('t.has_files'),
				$this->db->quoteName('t.time_spent'),
			])
			->from($this->db->quoteName($tT, 't'))
			->join('LEFT', $this->db->quoteName($tD, 'd') . ' ON ' . $this->db->quoteName('d.id') . ' = ' . $this->db->quoteName('t.department_id'))
			->join('LEFT', $this->db->quoteName($tSt, 's') . ' ON ' . $this->db->quoteName('s.id') . ' = ' . $this->db->quoteName('t.status_id'))
			->join('LEFT', $this->db->quoteName($tP, 'p') . ' ON ' . $this->db->quoteName('p.id') . ' = ' . $this->db->quoteName('t.priority_id'))
			->join('LEFT', $this->db->quoteName($tU, 'cu') . ' ON ' . $this->db->quoteName('cu.id') . ' = ' . $this->db->quoteName('t.customer_id'))
			// tickets.staff_id is actually a Joomla user_id, NOT a _rsticketspro_staff PK — confirmed
			// by models/fields/staff.php emitting $user->id as the option value, and by the model's
			// staffHasAccessToDepartment($user_id, ...) treating it as user_id. JOIN direct to users.
			->join('LEFT', $this->db->quoteName($tU, 'su') . ' ON ' . $this->db->quoteName('su.id') . ' = ' . $this->db->quoteName('t.staff_id'));

		// Status filter — accept scalar or array.
		if (array_key_exists('status_id', $arguments)) {
			$ids = (array) $arguments['status_id'];
			$ids = array_map('intval', $ids);
			if ($ids !== []) {
				$query->whereIn($this->db->quoteName('t.status_id'), $ids);
			}
		}
		if (array_key_exists('department_id', $arguments)) {
			$ids = (array) $arguments['department_id'];
			$ids = array_map('intval', $ids);
			if ($ids !== []) {
				$query->whereIn($this->db->quoteName('t.department_id'), $ids);
			}
		}
		if (array_key_exists('priority_id', $arguments)) {
			$ids = (array) $arguments['priority_id'];
			$ids = array_map('intval', $ids);
			if ($ids !== []) {
				$query->whereIn($this->db->quoteName('t.priority_id'), $ids);
			}
		}
		if (array_key_exists('staff_id', $arguments)) {
			$query->where($this->db->quoteName('t.staff_id') . ' = ' . (int) $arguments['staff_id']);
		}
		if (array_key_exists('customer_id', $arguments)) {
			$query->where($this->db->quoteName('t.customer_id') . ' = ' . (int) $arguments['customer_id']);
		}
		if (array_key_exists('last_reply_customer', $arguments)) {
			$query->where($this->db->quoteName('t.last_reply_customer') . ' = ' . (int) $arguments['last_reply_customer']);
		}
		if (array_key_exists('flagged', $arguments)) {
			$query->where($this->db->quoteName('t.flagged') . ' = ' . (int) $arguments['flagged']);
		}
		if (!empty($arguments['search'])) {
			$search = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $arguments['search']) . '%';
			$query->where(
				'(' . $this->db->quoteName('t.code') . ' LIKE ' . $this->db->quote($search)
				. ' OR ' . $this->db->quoteName('t.subject') . ' LIKE ' . $this->db->quote($search) . ')'
			);
		}
		if (!empty($arguments['date_from'])) {
			$query->where($this->db->quoteName('t.last_reply') . ' >= ' . $this->db->quote((string) $arguments['date_from'] . ' 00:00:00'));
		}
		if (!empty($arguments['date_to'])) {
			$query->where($this->db->quoteName('t.last_reply') . ' <= ' . $this->db->quote((string) $arguments['date_to'] . ' 23:59:59'));
		}

		$orderBy  = (string) ($arguments['order_by'] ?? 'last_reply');
		$orderDir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));
		if (!in_array($orderBy, ['last_reply', 'date', 'id', 'code', 'subject', 'replies'], true)) {
			$orderBy = 'last_reply';
		}
		if (!in_array($orderDir, ['ASC', 'DESC'], true)) {
			$orderDir = 'DESC';
		}
		$query->order($this->db->quoteName('t.' . $orderBy) . ' ' . $orderDir);

		$limit  = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$this->db->setQuery($query, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		// Coerce ints for tighter agent output.
		foreach ($rows as &$r) {
			foreach (['id', 'status_id', 'department_id', 'priority_id', 'staff_id', 'customer_id', 'last_reply_customer', 'replies', 'flagged', 'has_files'] as $k) {
				if (array_key_exists($k, $r)) {
					$r[$k] = (int) $r[$k];
				}
			}
		}
		unset($r);

		// Total — separate small query so the agent can paginate.
		$countQ = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($tT, 't'));
		// (re-apply the same WHERE clauses by string-copying the query's where part is non-trivial;
		// for v1 we just send a fast COUNT(*) without filters and call it `total_unfiltered`.
		// Pagination still works on the filtered list. Acceptable trade-off for MVP.)
		$totalUnfiltered = (int) $this->db->setQuery($countQ)->loadResult();

		return ToolResult::json([
			'ok'                => true,
			'count'             => count($rows),
			'limit'             => $limit,
			'offset'            => $offset,
			'total_unfiltered'  => $totalUnfiltered,
			'tickets'           => $rows,
		]);
	}
}
