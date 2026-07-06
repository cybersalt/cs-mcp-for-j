<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Fetch the contents of one specific admin-sidebar preset XML.
 *
 * Structured (component, name) input — NOT a raw path. The path is
 * constructed server-side as
 * `administrator/components/{component}/presets/{name}.xml`, both parts
 * regex-validated, then realpath-canonicalised and confirmed to stay inside
 * the allowlist base. That eliminates path-traversal, symlink escape, and
 * "trick it into reading configuration.php" by construction — the caller
 * cannot express a path that isn't a preset XML.
 *
 * Deliberately NOT a general read_file tool. See
 * ISSUE-6-get_admin_menu_preset-scoped-file-read.md for the threat model
 * that led to the narrow-scope decision.
 */
final class GetAdminMenuPresetTool extends AbstractTool
{
	use AdminMenuPresetPathTrait;

	public function getName(): string { return 'get_admin_menu_preset'; }

	public function getDescription(): string
	{
		return 'Read one admin-sidebar preset XML by (component, name). Returns the raw '
			. 'file contents plus size + mtime + sha256. Structured input only — the file '
			. 'path is built server-side as administrator/components/<component>/presets/'
			. '<name>.xml, both parts regex-validated. Refuses any name containing "..", '
			. 'path separators, or non-alphanumeric characters. Use list_admin_menu_presets '
			. 'first to discover what presets exist, then call this to inspect a specific '
			. 'one — e.g. to diff com_menus/default.xml against stock when a sidebar entry '
			. 'is reported missing.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['component', 'name'],
			'properties' => [
				'component' => [
					'type'        => 'string',
					'description' => 'Joomla component element, e.g. "com_menus", "com_content", "com_users". Must match ^com_[a-z0-9_]+$.',
				],
				'name' => [
					'type'        => 'string',
					'description' => 'Preset short name without extension, e.g. "default", "system", "content". Must match ^[a-z0-9_-]+$.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = $this->requireString($arguments, 'component');
		$name      = $this->requireString($arguments, 'name');

		try {
			$absolute = $this->resolvePresetPath($component, $name);
		} catch (PresetNotFoundException $e) {
			return ToolResult::error($e->getMessage());
		}

		try {
			$bytes = $this->readPresetBytes($absolute);
		} catch (\Throwable $e) {
			return ToolResult::error($e->getMessage());
		}

		$sha   = hash('sha256', $bytes);
		$stock = $this->stockHashLookup($component, $name, $sha);

		return ToolResult::json([
			'component'            => $component,
			'name'                 => $name,
			'path'                 => $this->normalizeToRelative($absolute),
			'size'                 => strlen($bytes),
			'mtime'                => date('c', filemtime($absolute)),
			'sha256'               => $sha,
			'stock_sha256'         => $stock['stock_sha256'],
			'matches_stock'        => $stock['matches_stock'],
			'stock_version_tested' => $stock['stock_version_tested'],
			'content'              => $bytes,
		]);
	}

	private function normalizeToRelative(string $absolute): string
	{
		if (str_starts_with($absolute, JPATH_ROOT)) {
			return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen(JPATH_ROOT))), '/');
		}
		return str_replace(DIRECTORY_SEPARATOR, '/', $absolute);
	}
}
