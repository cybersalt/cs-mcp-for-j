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
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_staff', 'stf') . ' ON stf.id = t.staff_id')
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'su') . ' ON su.id = stf.user_id')
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

		return ToolResult::json(['ok' => true, 'ticket' => $ticket]);
	}
}
