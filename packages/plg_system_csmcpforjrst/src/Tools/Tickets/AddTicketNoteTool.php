<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Add a staff-only internal note to a ticket. Goes into the separate
 * #__rsticketspro_ticket_notes table — invisible to the customer, only
 * shown in the staff-facing admin UI.
 *
 * Writes via the Ticketnotes JTable directly (the AdminModel save path
 * requires a form-bound flow that isn\'t worth the ceremony from MCP).
 * The table\'s store() still fires Joomla\'s table events, just not the
 * form pre-save validators.
 */
final class AddTicketNoteTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'add_rst_ticket_note'; }

	public function getDescription(): string
	{
		return 'Add a staff-only internal note to a RSTicketsPro ticket (#__rsticketspro_ticket_'
			. 'notes). Required: ticket_id, text. The note is NEVER shown to the customer — '
			. 'use add_rst_ticket_reply for customer-facing replies. Saved under the API '
			. 'token\'s Joomla user.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ticket_id', 'text'],
			'properties' => [
				'ticket_id' => ['type' => 'integer'],
				'text'      => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		$text     = $this->requireString($arguments, 'text');

		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		// Verify the ticket exists first — nicer error than a FK orphan note.
		$model = $this->rstModel('Ticket');
		if (!$model) {
			return ToolResult::error('Failed to load RsticketsproModelTicket — RSTicketsPro install may be broken.');
		}
		$ticket = $model->getTicket($ticketId);
		if (!$ticket || empty($ticket->id)) {
			return ToolResult::error('Ticket ' . $ticketId . ' not found.');
		}

		$table = $this->rstTable('Ticketnotes');
		if (!$table) {
			return ToolResult::error('Failed to load RsticketsproTableTicketnotes — RSTicketsPro install may be broken.');
		}

		$ok = $table->save([
			'ticket_id' => $ticketId,
			'user_id'   => Factory::getUser()->id,
			'text'      => $text,
			'date'      => Factory::getDate()->toSql(),
		]);
		if (!$ok) {
			return ToolResult::error('Saving note failed: ' . ($table->getError() ?: 'unknown error'));
		}

		return ToolResult::json([
			'ok'        => true,
			'note_id'   => (int) $table->id,
			'ticket_id' => $ticketId,
		]);
	}
}
