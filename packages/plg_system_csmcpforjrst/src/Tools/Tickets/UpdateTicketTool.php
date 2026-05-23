<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Update ticket-level fields (status / dept / priority / staff /
 * subject / customer / alternative_email) via RsticketsproModelTicket::
 * updateInfo(). That model method handles all the side effects:
 *
 *  - Department change → regenerates ticket code with new dept prefix,
 *    migrates same-named custom field values, unassigns staff if they
 *    don\'t have access to the new dept, writes a "department" system
 *    message in ticket_messages, fires notification_department_change
 *    email template.
 *  - Staff change → validates staff has access to current dept (errors
 *    if not), writes a "staff" system message, fires add_ticket_staff
 *    email to the new assignee.
 *  - Status change → standard status transition.
 *
 * For convenience, close_rst_ticket / reopen_rst_ticket are dedicated
 * wrappers around the status change (close also stops time tracking).
 */
final class UpdateTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'update_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Update one RSTicketsPro ticket\'s top-level fields via the same code path as the '
			. 'admin UI. Required: id. Any of: status_id, department_id (changes the ticket code '
			. 'too), priority_id, staff_id (a JOOMLA USER ID — 0 to unassign), subject, '
			. 'customer_id, alternative_email. NOTE on staff_id: despite the column name, '
			. 'tickets.staff_id stores a Joomla user_id, NOT the _rsticketspro_staff PK. Use '
			. 'list_rst_staff and read the user_id field for the value to pass here. Fires the '
			. 'usual side effects: department change regenerates the code + migrates matching '
			. 'custom fields + can unassign staff who lack access to the new dept; staff change '
			. 'validates dept access; system messages written to the ticket_messages thread; '
			. 'standard email notifications sent.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                => ['type' => 'integer'],
				'status_id'         => ['type' => 'integer'],
				'department_id'     => ['type' => 'integer'],
				'priority_id'       => ['type' => 'integer'],
				'staff_id'          => ['type' => 'integer', 'description' => 'JOOMLA USER ID of the assignee (NOT the _rsticketspro_staff PK — despite the column name). 0 = unassigned. Use list_rst_staff and read the user_id field of the staff member you want to assign.'],
				'subject'           => ['type' => 'string'],
				'customer_id'       => ['type' => 'integer', 'description' => 'Joomla user id of the customer.'],
				'alternative_email' => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$model = $this->rstModel('Ticket');
		if (!$model) {
			return ToolResult::error('Failed to load RsticketsproModelTicket — RSTicketsPro install may be broken.');
		}

		$original = $model->getTicket($id);
		if (!$original || empty($original->id)) {
			return ToolResult::error('Ticket ' . $id . ' not found.');
		}
		if (!$model->hasPermission($id)) {
			return ToolResult::error('Calling user lacks permission on ticket ' . $id . ': ' . ($model->getError() ?: 'permission denied'));
		}

		$data = [];
		foreach (['status_id', 'department_id', 'priority_id', 'staff_id', 'customer_id'] as $k) {
			if (array_key_exists($k, $arguments)) {
				$data[$k] = (int) $arguments[$k];
			}
		}
		foreach (['subject', 'alternative_email'] as $k) {
			if (array_key_exists($k, $arguments)) {
				$data[$k] = (string) $arguments[$k];
			}
		}

		if ($data === []) {
			return ToolResult::error('Nothing to update — supply at least one of the optional fields.');
		}

		// IMPORTANT — snapshot the pre-update values BEFORE the write. We can't compare
		// against $original after updateInfo() runs because the model calls
		// $original->bind($data) at model line 1189 to set up the email payload, which
		// mutates the JTable in place. Holding a reference to $original means our "from"
		// values silently become the new "to" values, and the changes diff comes back empty.
		$beforeSnapshot = [];
		foreach (array_keys($data) as $k) {
			$beforeSnapshot[$k] = $original->$k ?? null;
		}

		// updateInfo() fires emails on department change (notification_department_change)
		// and staff-assignment change (add_ticket_staff). Both build URLs with
		// Route::link('site', ...) which requires a SiteApplication. Wrap unconditionally
		// — the wrap is cheap when no email fires. See ISSUE-5 + RSTicketsProBootTrait.
		$this->withSiteAppContext(fn() => $model->updateInfo($id, $data));

		// IMPORTANT: cannot use $model->getTicket($id) here — RsticketsproModelTicket::getTicket()
		// caches statically per-id and updateInfo() does not invalidate the cache, so the next
		// call returns the stale pre-write row. Read fresh via direct SQL through the trait helper.
		$updated = $this->fetchTicketRow($id) ?? [];

		$changed = [];
		foreach ($beforeSnapshot as $k => $before) {
			$after = $updated[$k] ?? null;
			if ((string) $before !== (string) $after) {
				$changed[$k] = ['from' => $before, 'to' => $after];
			}
		}

		return ToolResult::json([
			'ok'            => true,
			'id'            => $id,
			'changes'       => $changed,
			'code'          => (string) ($updated['code'] ?? ''),
			'status_id'     => (int) ($updated['status_id'] ?? 0),
			'department_id' => (int) ($updated['department_id'] ?? 0),
			'priority_id'   => (int) ($updated['priority_id'] ?? 0),
			'staff_id'      => (int) ($updated['staff_id'] ?? 0),
			'staff_name'    => $updated['staff_name'] ?? null,
		]);
	}
}
