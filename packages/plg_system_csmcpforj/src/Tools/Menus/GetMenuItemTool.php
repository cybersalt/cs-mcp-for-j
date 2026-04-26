<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetMenuItemTool extends AbstractTool
{
	public function getName(): string { return 'get_menu_item'; }

	public function getDescription(): string { return 'Fetch a single menu item by id, including its params/link.'; }

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
			->from($this->db->quoteName('#__menu'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Menu item ' . $id . ' not found.');
		}
		$row['params'] = $row['params'] ? json_decode((string) $row['params'], true) : null;
		return ToolResult::json($row);
	}
}
