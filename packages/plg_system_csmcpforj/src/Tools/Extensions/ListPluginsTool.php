<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListPluginsTool extends AbstractTool
{
	public function getName(): string { return 'list_plugins'; }

	public function getDescription(): string
	{
		return 'List installed plugins, optionally filtered by group (system, content, '
			. 'authentication, etc.) and enabled state. Returns extension_id, element, '
			. 'folder, name, enabled, ordering.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'folder'  => ['type' => 'string', 'description' => 'Plugin group, e.g. "system", "content".'],
				'enabled' => ['type' => 'integer', 'enum' => [0, 1]],
				'search'  => ['type' => 'string'],
				'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'element', 'folder', 'name', 'enabled', 'ordering', 'protected']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->order($this->db->quoteName('folder') . ', ' . $this->db->quoteName('ordering') . ', ' . $this->db->quoteName('element'));

		if (!empty($arguments['folder'])) {
			$query->where($this->db->quoteName('folder') . ' = ' . $this->db->quote((string) $arguments['folder']));
		}
		if (isset($arguments['enabled'])) {
			$query->where($this->db->quoteName('enabled') . ' = ' . (int) $arguments['enabled']);
		}
		if (!empty($arguments['search'])) {
			$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
			$q    = $this->db->quote($like, false);
			$query->where('(' . $this->db->quoteName('name') . ' LIKE ' . $q . ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $q . ')');
		}

		$limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 200;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'plugins' => $rows]);
	}
}
