<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListMenuItemsTool extends AbstractTool
{
	public function getName(): string { return 'list_menu_items'; }

	public function getDescription(): string
	{
		return 'List menu items, optionally filtered by menutype, parent, language, or '
			. 'published state. Returns id, menutype, title, alias, level, parent_id, '
			. 'link, type, published, language, access.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'menutype'  => ['type' => 'string', 'description' => 'e.g. "mainmenu". Use list_menus to see options.'],
				'parent_id' => ['type' => 'integer'],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'language'  => ['type' => 'string'],
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'menutype', 'title', 'alias', 'level', 'parent_id', 'link', 'type', 'published', 'language', 'access', 'home', 'client_id']))
			->from($this->db->quoteName('#__menu'))
			->where($this->db->quoteName('id') . ' > 1') // skip ROOT
			->order($this->db->quoteName('lft') . ' ASC');

		if (!empty($arguments['menutype'])) {
			$query->where($this->db->quoteName('menutype') . ' = ' . $this->db->quote((string) $arguments['menutype']));
		}
		if (isset($arguments['parent_id'])) {
			$query->where($this->db->quoteName('parent_id') . ' = ' . (int) $arguments['parent_id']);
		}
		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}
		if (!empty($arguments['language'])) {
			$query->where($this->db->quoteName('language') . ' = ' . $this->db->quote((string) $arguments['language']));
		}
		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}

		$limit = isset($arguments['limit']) ? max(1, min(1000, (int) $arguments['limit'])) : 200;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'items' => $rows]);
	}
}
