<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * RSTicketsPro permission groups — each group has 25+ boolean perms
 * (add_ticket, delete_ticket, change_ticket_status, view_notes, etc.).
 * Useful when investigating "why couldn\'t this staff member do X."
 */
final class ListGroupsTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_groups'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro permission groups (#__rsticketspro_groups). Each row is a '
			. 'group with 25+ permission flags (add_ticket, delete_ticket, change_ticket_status, '
			. 'see_other_tickets, view_notes, etc.). Staff members belong to one group; the '
			. 'group determines what they can do.';
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
		$rows = $this->db->setQuery(
			$this->db->getQuery(true)
				->select('*')
				->from($this->db->quoteName($prefix . 'rsticketspro_groups'))
				->order($this->db->quoteName('id') . ' ASC')
		)->loadAssocList() ?: [];

		// Coerce every tinyint(1) column to bool for cleaner agent reading.
		$intCols = ['id'];
		foreach ($rows as &$r) {
			foreach ($r as $k => $v) {
				if (in_array($k, $intCols, true)) {
					$r[$k] = (int) $v;
				} elseif (is_numeric($v) && in_array((int) $v, [0, 1], true)) {
					$r[$k] = (bool) (int) $v;
				}
			}
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'groups' => $rows]);
	}
}
