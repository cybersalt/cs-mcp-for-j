<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Reopen a closed ticket. Sets status_id to RST_STATUS_OPEN (1) via
 * the standard updateInfo() flow.
 */
final class ReopenTicketTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'reopen_rst_ticket'; }

	public function getDescription(): string
	{
		return 'Reopen a closed RSTicketsPro ticket. Required: id. Sets status_id to 1 (open). '
			. 'Same effect as the admin UI \'Reopen\' button.';
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

		$openStatus = defined('RST_STATUS_OPEN') ? (int) RST_STATUS_OPEN : 1;
		// Wrap in withSiteAppContext — updateInfo()'s downstream paths build site
		// URLs via Route::link('site', ...) when any notification fires, which
		// requires a SiteApplication. See ISSUE-5 + RSTicketsProBootTrait.
		$this->withSiteAppContext(fn() => $model->updateInfo($id, ['status_id' => $openStatus]));

		// Direct SQL re-read — RST's getTicket() static cache isn't invalidated by updateInfo().
		$updated = $this->fetchTicketRow($id) ?? [];
		return ToolResult::json([
			'ok'        => true,
			'id'        => $id,
			'status_id' => (int) ($updated['status_id'] ?? 0),
			'status'    => (string) ($updated['status'] ?? ''),
		]);
	}
}
