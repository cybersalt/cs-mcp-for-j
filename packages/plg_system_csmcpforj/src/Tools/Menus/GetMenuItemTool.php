<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

final class GetMenuItemTool extends AbstractTool
{
	public function getName(): string { return 'get_menu_item'; }

	public function getDescription(): string
	{
		return 'Fetch a single menu item by id: every #__menu column (title, alias, note, menutype, '
			. 'parent_id, link, type, published, publish_up, publish_down, access, language, home, '
			. 'browserNav, template_style_id...), params decoded as stored (Link Type, Page Display, '
			. 'Metadata and the component view\'s own options; a missing key means "Use Global"), '
			. 'and, on multilingual sites, associations (language tag => menu item id).';
	}

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

		// Language associations — the Associations tab of the edit screen.
		if ((int) $row['client_id'] === 0 && Associations::isEnabled()) {
			$q = $this->db->getQuery(true)
				->select([$this->db->quoteName('m.id'), $this->db->quoteName('m.language')])
				->from($this->db->quoteName('#__associations', 'a'))
				->join('INNER', $this->db->quoteName('#__associations', 'b'), $this->db->quoteName('a.key') . ' = ' . $this->db->quoteName('b.key'))
				->join('INNER', $this->db->quoteName('#__menu', 'm'), $this->db->quoteName('m.id') . ' = ' . $this->db->quoteName('b.id'))
				->where($this->db->quoteName('a.context') . ' = ' . $this->db->quote('com_menus.item'))
				->where($this->db->quoteName('b.context') . ' = ' . $this->db->quote('com_menus.item'))
				->where($this->db->quoteName('a.id') . ' = :id')
				->where($this->db->quoteName('m.id') . ' != :self')
				->bind(':id', $id, ParameterType::INTEGER)
				->bind(':self', $id, ParameterType::INTEGER);

			$associations = [];
			foreach ($this->db->setQuery($q)->loadAssocList() as $assoc) {
				$associations[(string) $assoc['language']] = (int) $assoc['id'];
			}
			$row['associations'] = $associations;
		}

		return ToolResult::json($row);
	}
}
