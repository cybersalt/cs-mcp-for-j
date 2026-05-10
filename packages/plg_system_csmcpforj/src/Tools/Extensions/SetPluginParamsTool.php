<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Updates a Joomla plugin's params. Default mode is "merge": only the keys
 * the agent supplies are touched, every other existing key is preserved.
 * Set mode="replace" to overwrite the whole params object.
 *
 * Refuses to touch protected/locked core extensions to avoid bricking the
 * site by accidentally rewriting plg_system_logout's params (or whatever).
 */
final class SetPluginParamsTool extends AbstractTool
{
	public function getName(): string { return 'set_plugin_params'; }

	public function getDescription(): string
	{
		return 'Modify a Joomla plugin\'s params (its Options screen settings). Required: '
			. 'folder, element, params. Default mode "merge" preserves existing keys not in '
			. 'the supplied params object; mode="replace" overwrites the whole params object. '
			. 'Refuses protected/locked core plugins.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['folder', 'element', 'params'],
			'properties' => [
				'folder'  => ['type' => 'string'],
				'element' => ['type' => 'string'],
				'params'  => ['type' => 'object', 'description' => 'Key/value object to set.'],
				'mode'    => ['type' => 'string', 'enum' => ['merge', 'replace'], 'description' => 'Default merge.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$folder  = $this->requireString($arguments, 'folder');
		$element = $this->requireString($arguments, 'element');
		$incoming = $arguments['params'] ?? null;
		if (!is_array($incoming)) {
			return ToolResult::error('params must be an object.');
		}
		$mode = (string) ($arguments['mode'] ?? 'merge');
		if (!in_array($mode, ['merge', 'replace'], true)) {
			return ToolResult::error('mode must be merge or replace.');
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'protected', 'locked', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('Plugin not found: folder=' . $folder . ', element=' . $element);
		}
		if (!empty($row['protected']) || !empty($row['locked'])) {
			return ToolResult::error('Refusing to modify protected or locked core plugin.');
		}

		$existing = $row['params'] ? json_decode((string) $row['params'], true) : [];
		$existing = is_array($existing) ? $existing : [];

		$merged = $mode === 'replace' ? $incoming : array_replace_recursive($existing, $incoming);

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('params') . ' = ' . $this->db->quote(json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
			->where($this->db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'           => true,
			'extension_id' => (int) $row['extension_id'],
			'folder'       => $folder,
			'element'      => $element,
			'mode'         => $mode,
			'changed_keys' => array_keys($incoming),
			'params'       => $merged,
		]);
	}
}
