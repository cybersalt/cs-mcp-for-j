<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetModuleTool extends AbstractTool
{
	public function getName(): string { return 'get_module'; }

	public function getDescription(): string { return 'Fetch a single module by id, including content, params, and the menu assignments.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__modules'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Module ' . $id . ' not found.');
		}
		$row['params'] = $row['params'] ? json_decode((string) $row['params'], true) : null;

		// Menu assignments
		$mq = $this->db->getQuery(true)
			->select($this->db->quoteName('menuid'))
			->from($this->db->quoteName('#__modules_menu'))
			->where($this->db->quoteName('moduleid') . ' = ' . $id);
		$row['menu_assignments'] = $this->db->setQuery($mq)->loadColumn() ?: [];

		return ToolResult::json($row);
	}
}
