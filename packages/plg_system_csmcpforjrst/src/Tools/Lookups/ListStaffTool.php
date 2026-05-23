<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Staff members with their resolved Joomla user details + group + the
 * list of departments they have access to. Critical for the agent to
 * know who can be assigned to which tickets, and which Joomla user_ids
 * are the "is_staff" set used in get_rst_ticket_messages.
 *
 * NAMING TRAP — RSTicketsPro's `tickets.staff_id` column actually stores
 * a Joomla USER_ID, not the `_rsticketspro_staff` PK (verified in
 * models/fields/staff.php which emits $user->id as the dropdown's option
 * value). So when assigning a ticket via update_rst_ticket, pass the
 * `user_id` from this tool's output, NOT the `staff_id` (which is the
 * _rsticketspro_staff row PK and is mostly internal bookkeeping).
 */
final class ListStaffTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_staff'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro staff members (#__rsticketspro_staff). Returns staff_id (the '
			. 'PK of the _staff table — mostly internal), user_id (the JOOMLA USER ID — THIS is '
			. 'what tickets.staff_id stores despite its name, so use this for assignments), '
			. 'user_name, user_email, group_id, group_name, signature, and departments[] (the '
			. 'dept ids this staff member has access to). For update_rst_ticket(staff_id=...) '
			. 'or list_rst_tickets(staff_id=...) filters, pass the `user_id` field, NOT `staff_id`.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}
		$prefix = $this->db->getPrefix();

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('s.id', 'staff_id'),
				$this->db->quoteName('s.user_id'),
				$this->db->quoteName('u.name', 'user_name'),
				$this->db->quoteName('u.email', 'user_email'),
				$this->db->quoteName('u.block', 'user_blocked'),
				$this->db->quoteName('s.group_id'),
				$this->db->quoteName('g.name', 'group_name'),
				$this->db->quoteName('s.signature'),
				$this->db->quoteName('s.priority_id'),
				$this->db->quoteName('s.exclude_auto_assign'),
				$this->db->quoteName('s.can_delete_time_history'),
				$this->db->quoteName('s.can_delete_own_time_history'),
			])
			->from($this->db->quoteName($prefix . 'rsticketspro_staff', 's'))
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'u') . ' ON u.id = s.user_id')
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_groups', 'g') . ' ON g.id = s.group_id')
			->order($this->db->quoteName('s.id') . ' ASC');
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Departments a staff member has access to.
		$deptMap = [];
		if ($rows !== []) {
			$userIds = array_unique(array_map(fn($r) => (int) $r['user_id'], $rows));
			$dq = $this->db->getQuery(true)
				->select($this->db->quoteName(['user_id', 'department_id']))
				->from($this->db->quoteName($prefix . 'rsticketspro_staff_to_department'))
				->whereIn($this->db->quoteName('user_id'), $userIds);
			foreach ($this->db->setQuery($dq)->loadAssocList() ?: [] as $r) {
				$deptMap[(int) $r['user_id']][] = (int) $r['department_id'];
			}
		}

		foreach ($rows as &$r) {
			foreach (['staff_id', 'user_id', 'user_blocked', 'group_id', 'priority_id', 'exclude_auto_assign', 'can_delete_time_history', 'can_delete_own_time_history'] as $k) {
				$r[$k] = (int) $r[$k];
			}
			$r['departments'] = $deptMap[$r['user_id']] ?? [];
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'staff' => $rows]);
	}
}
