<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

final class ListPrioritiesTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_priorities'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro priorities (#__rsticketspro_priorities). Default install: '
			. '1=low, 2=normal, 3=high. Includes color hex values used in the admin UI.';
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
				->select($this->db->quoteName(['id', 'name', 'bg_color', 'fg_color', 'published', 'ordering']))
				->from($this->db->quoteName($prefix . 'rsticketspro_priorities'))
				->order($this->db->quoteName('ordering') . ' ASC')
		)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			$r['id']        = (int) $r['id'];
			$r['published'] = (int) $r['published'];
			$r['ordering']  = (int) $r['ordering'];
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'priorities' => $rows]);
	}
}
