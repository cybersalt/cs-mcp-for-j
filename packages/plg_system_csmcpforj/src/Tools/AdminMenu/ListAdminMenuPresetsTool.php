<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Enumerate every preset XML discoverable in the install under
 * `administrator/components/<component>/presets/*.xml`. Read-only.
 *
 * Companion to `get_admin_menu_preset`: use this to discover which presets
 * exist before pulling the contents of a specific one, and to spot presets
 * whose sha256 doesn't match the bundled stock hash for the running Joomla
 * version (indicates the file has been modified — attacker plant? sloppy
 * template dev? third-party postflight?).
 *
 * The Joomla admin sidebar is NOT stored in #__menu in Joomla 4+; it's
 * rendered at request time from these preset XMLs. When a Super User reports
 * a missing sidebar entry, this is where to look — not the existing menu
 * tools.
 */
final class ListAdminMenuPresetsTool extends AbstractTool
{
	use AdminMenuPresetPathTrait;

	public function getName(): string { return 'list_admin_menu_presets'; }

	public function getDescription(): string
	{
		return 'List every admin-sidebar preset XML installed on this site (files under '
			. 'administrator/components/<component>/presets/*.xml). The Joomla admin sidebar '
			. 'in J4+ is rendered from these files at request time — NOT from #__menu — so '
			. 'when someone reports a missing sidebar entry (Content > Fields disappeared, '
			. 'Users submenu is wrong, etc.), this is where to look. Returns per-preset '
			. 'metadata: component + name + relative path + size + mtime + sha256. When a '
			. 'bundled stock hash is available for the running Joomla version, also returns '
			. 'matches_stock so the caller can spot modified files in a single call. Use '
			. 'get_admin_menu_preset to pull the actual contents.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$presets = $this->discoverAllPresets();

		$out = [];
		foreach ($presets as $preset) {
			$abs   = $preset['absolute'];
			$sha   = @hash_file('sha256', $abs);
			$mtime = @filemtime($abs);
			$size  = @filesize($abs);
			if ($sha === false) {
				// A read failure is unusual for a file we just discovered via glob
				// — surface it in the row instead of silently omitting.
				$out[] = [
					'component' => $preset['component'],
					'name'      => $preset['name'],
					'path'      => $preset['path'],
					'error'     => 'Could not hash preset file.',
				];
				continue;
			}

			$stock = $this->stockHashLookup($preset['component'], $preset['name'], $sha);

			$out[] = [
				'component'            => $preset['component'],
				'name'                 => $preset['name'],
				'path'                 => $preset['path'],
				'size'                 => $size !== false ? $size : null,
				'mtime'                => $mtime !== false ? date('c', $mtime) : null,
				'sha256'               => $sha,
				'stock_sha256'         => $stock['stock_sha256'],
				'matches_stock'        => $stock['matches_stock'],
				'stock_version_tested' => $stock['stock_version_tested'],
			];
		}

		return ToolResult::json([
			'count'   => count($out),
			'presets' => $out,
			'note'    => 'When matches_stock is null, no bundled hash was available for '
				. 'this Joomla version + preset. Extend AdminMenuPresetPathTrait::'
				. 'stockHashLookup() to add hashes. sha256 is always returned so the '
				. 'caller can compare against an external reference regardless.',
		]);
	}
}
