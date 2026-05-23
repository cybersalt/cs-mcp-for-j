<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Toggle the flagged state on a ticket. Flagging is a personal-priority
 * marker; the admin UI shows flagged tickets with a star icon and they
 * can be filtered with the flagged=1 arg on list_rst_tickets.
 */
final class FlagTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'flag_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Set or unset the flagged marker on a RSTicketsPro ticket. Required: id, flagged '
			. '(1 to flag, 0 to unflag). Flagged tickets are filterable via list_rst_tickets'
			. '(flagged=1).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'flagged'],
			'properties' => [
				'id'      => ['type' => 'integer'],
				'flagged' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id      = $this->requirePositiveInt($arguments, 'id');
		$flagged = (int) ($arguments['flagged'] ?? 0);

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

		$model->setFlag($id, $flagged);

		return ToolResult::json([
			'ok'      => true,
			'id'      => $id,
			'flagged' => $flagged === 1,
		]);
	}
}
