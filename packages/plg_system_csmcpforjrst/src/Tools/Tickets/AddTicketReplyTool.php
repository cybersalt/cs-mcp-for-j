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
 * Post a reply to a ticket. Calls RsticketsproModelTicket::reply(), which
 * fires all the right downstream effects:
 *  - inserts the message row in #__rsticketspro_ticket_messages
 *  - updates the ticket's last_reply, last_reply_customer, replies count
 *  - sends the add_ticket_reply_customer / add_ticket_reply_staff email
 *    notifications via 4SEO's standard templates
 *  - writes to ticket_history
 *  - fires the onBeforeStoreTicketReply / onAfterStoreTicketReply events
 *    so any other extensions hooked into the flow run too
 *
 * This is the same code path the admin UI's reply box uses. Posting via
 * MCP is indistinguishable from a human staff member typing in the admin
 * reply box, except for the user_id (which is the API token's user).
 *
 * File attachments NOT supported in v1 — would need multipart upload through
 * the MCP layer which adds protocol complexity. Use the admin UI for any
 * reply that needs attachments.
 */
final class AddTicketReplyTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'add_rst_ticket_reply'; }

	public function getDescription(): string
	{
		return 'Post a reply to a RSTicketsPro ticket. Required: ticket_id, message. Optional: '
			. 'html (1 if message is rich HTML, 0 if plaintext — default 1), use_signature '
			. '(1 to append the staff member\'s signature, 0 to skip — default 1 for staff), '
			. 'reply_as_customer (1 to record as customer-reply, 0 as staff-reply — default 0 '
			. 'when posted by a staff member). Fires the standard add_ticket_reply_customer '
			. '/ add_ticket_reply_staff email notifications and updates ticket\'s last_reply '
			. 'fields and replies count. Refuses on a status=2 (closed) ticket — reopen first '
			. 'with reopen_rst_ticket. NOTE: file attachments are not supported via MCP in this '
			. 'version — use the admin UI for replies that need attachments. The reply is '
			. 'posted under the API token\'s Joomla user, which must be an RSTicketsPro staff '
			. 'member for the reply to count as staff (otherwise it\'s posted as customer).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ticket_id', 'message'],
			'properties' => [
				'ticket_id'         => ['type' => 'integer'],
				'message'           => ['type' => 'string', 'description' => 'The reply body. HTML or plaintext per the html flag.'],
				'html'              => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 1.'],
				'use_signature'     => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 1 — append staff signature.'],
				'reply_as_customer' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 0 — record this as a staff reply. Set 1 if a staff member is posting on the customer\'s behalf.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		$message  = $this->requireString($arguments, 'message');

		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$model = $this->rstModel('Ticket');
		if (!$model) {
			return ToolResult::error('Failed to load RsticketsproModelTicket — RSTicketsPro install may be broken.');
		}

		$ticket = $model->getTicket($ticketId);
		if (!$ticket || empty($ticket->id)) {
			return ToolResult::error('Ticket ' . $ticketId . ' not found.');
		}

		// Status check matches the controller — RST_STATUS_CLOSED = 2.
		$closedStatus = defined('RST_STATUS_CLOSED') ? (int) RST_STATUS_CLOSED : 2;
		if ((int) $ticket->status_id === $closedStatus) {
			return ToolResult::error('Ticket ' . $ticketId . ' is closed. Reopen it first with reopen_rst_ticket if you need to reply.');
		}

		if (!$model->hasPermission($ticketId)) {
			return ToolResult::error('Calling user lacks permission to reply to ticket ' . $ticketId . ': ' . ($model->getError() ?: 'permission denied'));
		}

		// Build the data array exactly the way the controller does.
		$data = [
			'id'                => null,
			'ticket_id'         => $ticketId,
			'user_id'           => Factory::getUser()->id,
			'date'              => Factory::getDate()->toSql(),
			'message'           => $message,
			'html'              => (int) ($arguments['html'] ?? 1),
			'use_signature'     => (int) ($arguments['use_signature'] ?? 1),
			'reply_as_customer' => (int) ($arguments['reply_as_customer'] ?? 0),
			// admin-side consent (mirrors controller line 758-760 — admin replies are always consented).
			'consent'           => [1],
		];

		if (!$model->reply($ticketId, $data, [])) {
			return ToolResult::error('Reply failed: ' . ($model->getError() ?: 'unknown error from RSTicketsPro'));
		}

		// Re-fetch so we can return the updated ticket state.
		$updated = $model->getTicket($ticketId);
		return ToolResult::json([
			'ok'                  => true,
			'ticket_id'           => $ticketId,
			'replies'             => (int) ($updated->replies ?? 0),
			'last_reply'          => (string) ($updated->last_reply ?? ''),
			'last_reply_customer' => (int) ($updated->last_reply_customer ?? 0),
			'status_id'           => (int) ($updated->status_id ?? 0),
			'note'                => 'Reply posted via RSTicketsPro\'s standard reply flow — customer + staff notification emails sent per the configured templates.',
		]);
	}
}
