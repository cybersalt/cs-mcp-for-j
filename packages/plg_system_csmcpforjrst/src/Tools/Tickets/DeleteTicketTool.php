<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Permanent delete. Calls RsticketsproModelTicket::delete() which also
 * removes the ticket\'s messages, history, notes, files, and custom field
 * values (cascading via the model\'s own cleanup pass).
 *
 * Requires explicit confirm=true to guard against agent slips.
 */
final class DeleteTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'delete_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Permanently delete a RSTicketsPro ticket and all its messages, history, notes, '
			. 'attachments, and custom field values. Required: id, confirm (must be true — '
			. 'the explicit flag guards against accidental destructive calls). Caller must be '
			. 'in a RSTicketsPro group with delete_ticket permission. Consider close_rst_ticket '
			. 'instead if you just want to mark a ticket resolved — closing is reversible, '
			. 'deletion isn\'t.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'confirm'],
			'properties' => [
				'id'      => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true. Refuses otherwise.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id      = $this->requirePositiveInt($arguments, 'id');
		$confirm = (bool) ($arguments['confirm'] ?? false);
		if (!$confirm) {
			return ToolResult::error('Refusing to delete ticket ' . $id . ' without confirm=true. Pass confirm:true to proceed.');
		}

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

		$idVar = $id;
		$result = $model->delete($idVar);
		if (!$result) {
			return ToolResult::error('Delete failed: ' . ($model->getError() ?: 'unknown error'));
		}

		return ToolResult::json(['ok' => true, 'id' => $id, 'deleted' => true]);
	}
}
