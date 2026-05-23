<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Send the autoclose-warning email ("your ticket will be closed soon")
 * for a single ticket. Requires autoclose_enabled=1 in the
 * #__rsticketspro_configuration table — the model refuses otherwise.
 */
final class NotifyTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'notify_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Send the autoclose-warning email ("your ticket will be closed") for one '
			. 'RSTicketsPro ticket. Required: id. Refuses if autoclose_enabled is off in '
			. 'the configuration. Sets autoclose_sent on the ticket so the next autoclose '
			. 'cron pass will actually close it (after the configured interval).';
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

		// Mirror the controller's preflight — refuse if autoclose isn't enabled.
		if (class_exists('RSTicketsProHelper') && !\RSTicketsProHelper::getConfig('autoclose_enabled')) {
			return ToolResult::error('autoclose_enabled is off in the RSTicketsPro configuration — notify_rst_ticket would be a no-op.');
		}

		$model = $this->rstModel('Ticket');
		if (!$model) {
			return ToolResult::error('Failed to load RsticketsproModelTicket.');
		}
		if (!$model->hasPermission($id)) {
			return ToolResult::error('Calling user lacks permission on ticket ' . $id . ': ' . ($model->getError() ?: 'permission denied'));
		}

		$model->notify($id);

		return ToolResult::json(['ok' => true, 'id' => $id, 'notified' => true]);
	}
}
