<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Full single-ticket read. Returns the same shape as list_rst_tickets but
 * with the values fully resolved + extras: signature, time_spent breakdown,
 * autoclose_sent, custom field values.
 */
final class GetTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'get_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Get full details for one RSTicketsPro ticket. Required: id. Returns all ticket '
			. 'columns + resolved department/status/priority/staff/customer names, plus any custom '
			. 'field values. Use get_rst_ticket_messages for the conversation thread, '
			. 'get_rst_ticket_history for the audit log, get_rst_ticket_notes for staff-only notes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$prefix = $this->db->getPrefix();

		$query = $this->db->getQuery(true)
			->select('t.*')
			->select($this->db->quoteName('s.name', 'status'))
			->select($this->db->quoteName('d.name', 'department'))
			->select($this->db->quoteName('p.name', 'priority'))
			->select($this->db->quoteName('cu.name', 'customer_name'))
			->select($this->db->quoteName('cu.email', 'customer_email'))
			->select($this->db->quoteName('su.name', 'staff_name'))
			->select($this->db->quoteName('su.email', 'staff_email'))
			->from($this->db->quoteName($prefix . 'rsticketspro_tickets', 't'))
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_departments', 'd') . ' ON d.id = t.department_id')
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_statuses', 's') . ' ON s.id = t.status_id')
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_priorities', 'p') . ' ON p.id = t.priority_id')
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'cu') . ' ON cu.id = t.customer_id')
			// tickets.staff_id is a Joomla user_id, NOT the _rsticketspro_staff PK — confirmed in
			// models/fields/staff.php (option value = $user->id) + model's staffHasAccessToDepartment
			// taking the value as $user_id. Direct JOIN to users.
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'su') . ' ON su.id = t.staff_id')
			->where($this->db->quoteName('t.id') . ' = ' . $id);

		$ticket = $this->db->setQuery($query)->loadAssoc();
		if (!$ticket) {
			return ToolResult::error('Ticket ' . $id . ' not found.');
		}

		// Coerce ints.
		foreach (['id', 'status_id', 'department_id', 'priority_id', 'staff_id', 'customer_id', 'last_reply_customer', 'replies', 'flagged', 'has_files', 'autoclose_sent', 'logged', 'feedback', 'followup_sent'] as $k) {
			if (array_key_exists($k, $ticket)) {
				$ticket[$k] = (int) $ticket[$k];
			}
		}

		// Custom field values for this ticket.
		$cfQ = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('cf.id', 'custom_field_id'),
				$this->db->quoteName('cf.name'),
				$this->db->quoteName('cf.label'),
				$this->db->quoteName('cf.type'),
				$this->db->quoteName('v.value'),
			])
			->from($this->db->quoteName($prefix . 'rsticketspro_custom_fields_values', 'v'))
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_custom_fields', 'cf') . ' ON cf.id = v.custom_field_id')
			->where($this->db->quoteName('v.ticket_id') . ' = ' . $id);
		$ticket['custom_fields'] = $this->db->setQuery($cfQ)->loadAssocList() ?: [];

		$ticket['autoclose'] = $this->buildAutocloseBlock($ticket);

		return ToolResult::json(['ok' => true, 'ticket' => $ticket]);
	}

	/**
	 * Build the autoclose summary for one ticket. RST's autoclose is a two-phase
	 * flow per the model + helper source:
	 *   1. After `last_reply + autoclose_email_interval` days, if conditions are
	 *      met (last_reply_customer=0, autoclose_sent=0, status != closed), the
	 *      notify_rst_ticket / cron sends a warning email and sets autoclose_sent=1.
	 *   2. Some configurable interval later (`autoclose_interval` days), if
	 *      autoclose_automatically=1, the autoclose cron closes the ticket.
	 *
	 * Surfaced here so the agent can answer "when will this ticket autoclose?"
	 * without having to know the config layout or do the arithmetic itself.
	 */
	private function buildAutocloseBlock(array $ticket): array
	{
		$enabled         = (string) $this->getRstConfig('autoclose_enabled') === '1';
		$automatic       = (string) $this->getRstConfig('autoclose_automatically') === '1';
		$warnIntervalRaw = (int) $this->getRstConfig('autoclose_email_interval');
		$closeIntervalRaw = (int) $this->getRstConfig('autoclose_interval');
		// RST clamps warn-interval to a minimum of 1 day in models/ticket.php::notify().
		$warnInterval  = max(1, $warnIntervalRaw);
		$closeInterval = max(0, $closeIntervalRaw);

		$out = [
			'enabled'                     => $enabled,
			'automatic'                   => $automatic,
			'warning_email_interval_days' => $warnInterval,
			'close_interval_days'         => $closeInterval,
			'warning_sent'                => (bool) ($ticket['autoclose_sent'] ?? 0),
			'warning_eta'                 => null,
			'close_eta'                   => null,
			'blocked_by'                  => null,
		];

		if (!$enabled) {
			$out['blocked_by'] = 'autoclose_enabled=0';
			return $out;
		}
		if ((int) ($ticket['status_id'] ?? 0) === 2) {
			$out['blocked_by'] = 'already_closed';
			return $out;
		}
		if ((int) ($ticket['last_reply_customer'] ?? 0) === 1) {
			$out['blocked_by'] = 'last_reply_customer=1 (we owe the customer, not the other way around)';
			return $out;
		}

		$lastReply = (string) ($ticket['last_reply'] ?? '');
		if ($lastReply === '' || str_starts_with($lastReply, '0000-')) {
			$out['blocked_by'] = 'no_valid_last_reply';
			return $out;
		}

		try {
			$lastReplyTs = strtotime($lastReply . ' UTC');
		} catch (\Throwable $e) {
			$lastReplyTs = false;
		}
		if ($lastReplyTs === false) {
			$out['blocked_by'] = 'could_not_parse_last_reply';
			return $out;
		}

		if (!$out['warning_sent']) {
			$out['warning_eta'] = gmdate('Y-m-d H:i:s', $lastReplyTs + $warnInterval * 86400);
		}
		if ($automatic) {
			// Close ETA = warning ETA + close_interval days. If the warning was already
			// sent, count from the warning time — but RST doesn't record that timestamp
			// separately; the warning ETA is the best lower bound we have.
			$warningTs = $out['warning_sent'] ? $lastReplyTs : $lastReplyTs + $warnInterval * 86400;
			$out['close_eta'] = gmdate('Y-m-d H:i:s', $warningTs + $closeInterval * 86400);
		} else {
			$out['blocked_by'] = 'autoclose_automatically=0 (warnings only — human must close manually)';
		}

		return $out;
	}
}
