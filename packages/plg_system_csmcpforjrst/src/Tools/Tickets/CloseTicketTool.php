<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Close a ticket. Mirrors the admin controller\'s changeTicketStatus()
 * case for task=close: sets status_id to RST_STATUS_CLOSED (2) AND stops
 * the time-tracking timer if it\'s running. update_rst_ticket would set
 * the status, but only this wrapper also stops the timer — match the
 * admin UI\'s "Close" button behaviour exactly.
 */
final class CloseTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'close_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Close a RSTicketsPro ticket. Required: id. Sets status_id to 2 (closed) AND '
			. 'stops the time-tracking timer for the ticket (matches the admin UI \'Close\' '
			. 'button behaviour — update_rst_ticket(status_id=2) does not stop the timer). '
			. 'Fires the autoclose-followup email if configured. Use reopen_rst_ticket to '
			. 'put it back into open state.';
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

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$model = $this->rstModel('Ticket');
		if (!$model) {
			return ToolResult::error('Failed to load RsticketsproModelTicket.');
		}
		if (!$model->hasPermission($id)) {
			return ToolResult::error('Calling user lacks permission on ticket ' . $id . ': ' . ($model->getError() ?: 'permission denied'));
		}

		$closedStatus = defined('RST_STATUS_CLOSED') ? (int) RST_STATUS_CLOSED : 2;
		$model->updateInfo($id, ['status_id' => $closedStatus]);
		// Match the controller — stop time tracking on close.
		$model->toggleTime($id, 0);

		$updated = $model->getTicket($id);
		return ToolResult::json([
			'ok'         => true,
			'id'         => $id,
			'status_id'  => (int) ($updated->status_id ?? 0),
			'closed'     => (string) ($updated->closed ?? ''),
			'time_spent' => (string) ($updated->time_spent ?? '0.00'),
		]);
	}
}
