<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

final class ListStatusesTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_statuses'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro statuses (#__rsticketspro_statuses). Default install: 1=open, '
			. '2=closed, 3=on-hold. Custom installs may add more. Use the id with status_id '
			. 'filters or update_rst_ticket.';
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
				->select($this->db->quoteName(['id', 'name', 'published', 'ordering']))
				->from($this->db->quoteName($prefix . 'rsticketspro_statuses'))
				->order($this->db->quoteName('ordering') . ' ASC')
		)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			$r['id']        = (int) $r['id'];
			$r['published'] = (int) $r['published'];
			$r['ordering']  = (int) $r['ordering'];
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'statuses' => $rows]);
	}
}
