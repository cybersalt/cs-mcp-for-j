<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads any Joomla plugin's params (the Options screen settings) directly
 * from #__extensions. Direct DB read, NOT ComponentHelper::getParams() —
 * because if the agent is going to write back via set_plugin_params in the
 * same conversation, the cached layer can return stale data.
 *
 * Site-wide schema config (plg_system_schemaorg's Organization/Person/WebSite
 * defaults), router options, cache settings, two-factor settings, third-party
 * plugin config — all live here.
 */
final class GetPluginParamsTool extends AbstractTool
{
	public function getName(): string { return 'get_plugin_params'; }

	public function getDescription(): string
	{
		return 'Read a Joomla plugin\'s params (its Options screen settings). Required: '
			. 'folder (group, e.g. "system", "content", "authentication") and element '
			. '(e.g. "schemaorg", "csmcpforj"). Returns the full params JSON as an object. '
			. 'Use list_plugins to discover folder/element pairs. Pair with set_plugin_params '
			. 'to modify settings (e.g. site-wide Organization schema lives in plg_system_schemaorg).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['folder', 'element'],
			'properties' => [
				'folder'  => ['type' => 'string', 'description' => 'Plugin group: system, content, authentication, user, editor, etc.'],
				'element' => ['type' => 'string', 'description' => 'Plugin element name (lowercase).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$folder  = $this->requireString($arguments, 'folder');
		$element = $this->requireString($arguments, 'element');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'name', 'enabled', 'protected', 'locked', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('Plugin not found: folder=' . $folder . ', element=' . $element);
		}

		$params = $row['params'] ? json_decode((string) $row['params'], true) : [];
		return ToolResult::json([
			'extension_id' => (int) $row['extension_id'],
			'name'         => $row['name'],
			'folder'       => $folder,
			'element'      => $element,
			'enabled'      => (int) $row['enabled'] === 1,
			'protected'    => (int) ($row['protected'] ?? 0) === 1,
			'locked'       => (int) ($row['locked'] ?? 0) === 1,
			'params'       => is_array($params) ? $params : [],
		]);
	}
}
