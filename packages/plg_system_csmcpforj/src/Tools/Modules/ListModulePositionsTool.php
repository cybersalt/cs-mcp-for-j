<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Returns the list of module positions in active use across the site, derived
 * from #__modules. Does not parse template manifests (which would require
 * access to template XML files).
 */
final class ListModulePositionsTool extends AbstractTool
{
	public function getName(): string { return 'list_module_positions'; }

	public function getDescription(): string
	{
		return 'List module positions currently in use, optionally filtered by client_id '
			. '(0=site, 1=admin). Returns each position with the count of modules assigned.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('position') . ', COUNT(*) AS module_count')
			->from($this->db->quoteName('#__modules'))
			->where($this->db->quoteName('position') . ' != ' . $this->db->quote(''))
			->group($this->db->quoteName('position'))
			->order($this->db->quoteName('position') . ' ASC');

		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'positions' => $rows]);
	}
}
