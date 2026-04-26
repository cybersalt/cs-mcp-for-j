<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListExtensionsTool extends AbstractTool
{
	public function getName(): string { return 'list_extensions'; }

	public function getDescription(): string
	{
		return 'List installed extensions of any type (component, plugin, module, template, '
			. 'library, package, language, file). Useful for surveying what is on a site. '
			. 'Returns extension_id, type, element, name, folder, client_id, enabled, state, manifest_cache (when small).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'type'      => ['type' => 'string', 'description' => 'component | plugin | module | template | library | package | language | file'],
				'folder'    => ['type' => 'string', 'description' => 'For plugins, the group (system, content, etc.).'],
				'enabled'   => ['type' => 'integer', 'enum' => [0, 1]],
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
				'search'    => ['type' => 'string', 'description' => 'Substring of name or element.'],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'type', 'element', 'name', 'folder', 'client_id', 'enabled', 'state', 'protected', 'locked']))
			->from($this->db->quoteName('#__extensions'))
			->order($this->db->quoteName('type') . ', ' . $this->db->quoteName('folder') . ', ' . $this->db->quoteName('element'));

		if (!empty($arguments['type'])) {
			$query->where($this->db->quoteName('type') . ' = ' . $this->db->quote((string) $arguments['type']));
		}
		if (!empty($arguments['folder'])) {
			$query->where($this->db->quoteName('folder') . ' = ' . $this->db->quote((string) $arguments['folder']));
		}
		if (isset($arguments['enabled'])) {
			$query->where($this->db->quoteName('enabled') . ' = ' . (int) $arguments['enabled']);
		}
		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}
		if (!empty($arguments['search'])) {
			$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
			$q    = $this->db->quote($like, false);
			$query->where('(' . $this->db->quoteName('name') . ' LIKE ' . $q . ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $q . ')');
		}

		$limit = isset($arguments['limit']) ? max(1, min(1000, (int) $arguments['limit'])) : 200;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'extensions' => $rows]);
	}
}
