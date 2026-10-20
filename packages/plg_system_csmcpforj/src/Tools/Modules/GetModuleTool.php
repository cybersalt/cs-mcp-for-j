<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

final class GetModuleTool extends AbstractTool
{
	public function getName(): string { return 'get_module'; }

	public function getDescription(): string
	{
		return 'Fetch a single module by id: every #__modules column (title, note, position, ordering, '
			. 'published, publish_up, publish_down, access, showtitle, language, content...), params '
			. 'decoded, the raw menu_assignments rows, the assignment in update_module\'s terms '
			. '(assigned: -1 all pages, 0 none, 1 only, 2 all except; assignment: menu item ids), '
			. 'and, on multilingual sites, associations (language tag => module id).';
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
			->from($this->db->quoteName('#__modules'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Module ' . $id . ' not found.');
		}
		$row['params'] = $row['params'] ? json_decode((string) $row['params'], true) : null;

		// Menu assignments, raw: 0 = all pages, positive = only on, negative = all except.
		$mq = $this->db->getQuery(true)
			->select($this->db->quoteName('menuid'))
			->from($this->db->quoteName('#__modules_menu'))
			->where($this->db->quoteName('moduleid') . ' = ' . $id);
		$menuIds = array_map('intval', $this->db->setQuery($mq)->loadColumn() ?: []);
		$row['menu_assignments'] = $menuIds;

		// The same, in the vocabulary update_module/create_module take, so a
		// read can be fed straight back. Mirrors ModuleModel::getItem().
		if ($menuIds === []) {
			$row['assigned']   = 0;
			$row['assignment'] = [];
		} elseif ($menuIds[0] === 0) {
			$row['assigned']   = -1;
			$row['assignment'] = [];
		} else {
			$row['assigned']   = $menuIds[0] > 0 ? 1 : 2;
			$row['assignment'] = array_values(array_filter(array_map(static fn ($v) => abs($v), $menuIds)));
		}

		if ((int) $row['client_id'] === 0 && Associations::isEnabled()) {
			$db = $this->db;
			$q  = $db->getQuery(true)
				->select([$db->quoteName('m.id'), $db->quoteName('m.language')])
				->from($db->quoteName('#__associations', 'a'))
				->join('INNER', $db->quoteName('#__associations', 'b'), $db->quoteName('a.key') . ' = ' . $db->quoteName('b.key'))
				->join('INNER', $db->quoteName('#__modules', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('b.id'))
				->where($db->quoteName('a.context') . ' = ' . $db->quote('com_modules.item'))
				->where($db->quoteName('b.context') . ' = ' . $db->quote('com_modules.item'))
				->where($db->quoteName('a.id') . ' = :id')
				->where($db->quoteName('m.id') . ' != :self')
				->bind(':id', $id, ParameterType::INTEGER)
				->bind(':self', $id, ParameterType::INTEGER);

			$associations = [];
			foreach ($db->setQuery($q)->loadAssocList() as $assoc) {
				$associations[(string) $assoc['language']] = (int) $assoc['id'];
			}
			$row['associations'] = $associations;
		}

		return ToolResult::json($row);
	}
}
