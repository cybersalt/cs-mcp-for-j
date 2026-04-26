<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListMenusTool extends AbstractTool
{
	public function getName(): string { return 'list_menus'; }

	public function getDescription(): string
	{
		return 'List menu types (menu containers) — for example "mainmenu", "usermenu". '
			. 'Each row gives the menutype string used by create_menu_item and list_menu_items.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'client_id' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '0=site, 1=admin. Default 0.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clientId = isset($arguments['client_id']) ? (int) $arguments['client_id'] : 0;

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'menutype', 'title', 'description', 'client_id']))
			->from($this->db->quoteName('#__menu_types'))
			->where($this->db->quoteName('client_id') . ' = ' . $clientId)
			->order($this->db->quoteName('title') . ' ASC');

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'menus' => $rows]);
	}
}
