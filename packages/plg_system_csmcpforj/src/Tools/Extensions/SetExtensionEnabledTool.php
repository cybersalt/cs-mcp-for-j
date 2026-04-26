<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Enable / disable any extension by id. Refuses to touch protected or locked
 * core extensions, since disabling those would brick the site.
 */
final class SetExtensionEnabledTool extends AbstractTool
{
	public function getName(): string { return 'set_extension_enabled'; }

	public function getDescription(): string
	{
		return 'Enable or disable an installed extension by extension_id. Use list_extensions or '
			. 'list_plugins first. Refuses protected/locked core extensions.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['extension_id', 'enabled'],
			'properties' => [
				'extension_id' => ['type' => 'integer'],
				'enabled'      => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$extensionId = (int) ($arguments['extension_id'] ?? 0);
		if ($extensionId <= 0) {
			return ToolResult::error('extension_id is required.');
		}
		if (!array_key_exists('enabled', $arguments)) {
			return ToolResult::error('enabled is required.');
		}
		$enabled = (int) $arguments['enabled'] === 1 ? 1 : 0;

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'type', 'element', 'folder', 'name', 'enabled', 'protected', 'locked']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('extension_id') . ' = ' . $extensionId);
		$ext = $this->db->setQuery($query)->loadAssoc();

		if (!$ext) {
			return ToolResult::error('Extension ' . $extensionId . ' not found.');
		}
		if (!empty($ext['protected']) || !empty($ext['locked'])) {
			return ToolResult::error('Refusing to change a protected or locked extension: ' . $ext['name']);
		}

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('enabled') . ' = ' . $enabled)
			->where($this->db->quoteName('extension_id') . ' = ' . $extensionId);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'           => true,
			'extension_id' => $extensionId,
			'name'         => $ext['name'],
			'type'         => $ext['type'],
			'element'      => $ext['element'],
			'folder'       => $ext['folder'],
			'enabled'      => $enabled,
		]);
	}
}
