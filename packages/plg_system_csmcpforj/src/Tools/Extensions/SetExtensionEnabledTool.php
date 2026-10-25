<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Enable / disable any extension by id, with the same rules as Joomla's
 * Extensions → Manage screen:
 *
 * - `protected = 1` can't be disabled (core parts the site needs).
 * - `locked = 1` only means "can't be uninstalled". Since Joomla 5 nearly
 *   every core extension is locked, and the admin can still switch them on
 *   and off, so the lock is not a reason to refuse (up to 2.9.4 it was, #37).
 * - A template with a default style, or with child templates, can't be
 *   disabled.
 *
 * On top of that, a short deny-list stops disabling the pieces that would cut
 * off this MCP connection or the admin login.
 */
final class SetExtensionEnabledTool extends AbstractTool
{
	/** [type, folder, element] that are never disabled through MCP. */
	private const KEEP_ENABLED = [
		['package', '', 'pkg_csmcpforj', 'it is MCP for J itself'],
		['component', '', 'com_csmcpforj', 'it is MCP for J itself'],
		['plugin', 'system', 'csmcpforj', 'it is MCP for J itself'],
		['plugin', 'webservices', 'csmcpforj', 'it serves this MCP endpoint'],
		['plugin', 'api-authentication', 'csmcpforj', 'it signs in apps connected with OAuth'],
		['plugin', 'api-authentication', 'token', 'it checks the API tokens MCP clients use'],
		['plugin', 'user', 'token', 'it holds the API tokens MCP clients use'],
		['plugin', 'authentication', 'joomla', 'it is the normal username/password login'],
		['plugin', 'user', 'joomla', 'it is needed for every login'],
	];

	public function getName(): string { return 'set_extension_enabled'; }

	public function getDescription(): string
	{
		return 'Enable or disable an installed extension by extension_id. Use list_extensions or '
			. 'list_plugins first. Follows Joomla\'s own rules: protected extensions can\'t be disabled, '
			. 'but locked ones (most core plugins, e.g. System - Schema.org) can, because "locked" only '
			. 'means they can\'t be uninstalled. A template that has a default style or child templates '
			. 'can\'t be disabled. MCP for J itself, the API token plugins and the Joomla login '
			. 'plugins are never disabled through MCP.';
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
		if (!$actor->authorise('core.admin')) {
			return ToolResult::error(
				'set_extension_enabled requires Super User (core.admin). The csmcpforj.write grant alone '
				. 'is not sufficient because disabling an extension can switch off security plugins such as two-factor authentication or a firewall.'
			);
		}

		$extensionId = (int) ($arguments['extension_id'] ?? 0);
		if ($extensionId <= 0) {
			return ToolResult::error('extension_id is required.');
		}
		if (!array_key_exists('enabled', $arguments)) {
			return ToolResult::error('enabled is required.');
		}
		$enabled = (int) $arguments['enabled'] === 1 ? 1 : 0;

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id', 'name', 'enabled', 'protected', 'locked']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('extension_id') . ' = ' . $extensionId);
		$ext = $this->db->setQuery($query)->loadAssoc();

		if (!$ext) {
			return ToolResult::error('Extension ' . $extensionId . ' not found.');
		}

		if ($enabled === 0) {
			$refusal = $this->disableRefusal($ext);

			if ($refusal !== null) {
				return ToolResult::error($refusal);
			}
		}

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('enabled') . ' = ' . $enabled)
			->where($this->db->quoteName('extension_id') . ' = ' . $extensionId);
		$this->db->setQuery($update)->execute();

		// The same caches Joomla's Extensions → Manage clears after a state change.
		foreach (['_system', 'com_modules', 'mod_menu', 'com_plugins'] as $group) {
			try {
				$cache = Factory::getCache($group, '');
				$cache->setCaching(true);
				$cache->clean();
			} catch (\Throwable $e) {
			}
		}

		return ToolResult::json([
			'ok'               => true,
			'extension_id'     => $extensionId,
			'name'             => $ext['name'],
			'type'             => $ext['type'],
			'element'          => $ext['element'],
			'folder'           => $ext['folder'],
			'enabled'          => $enabled,
			'previous_enabled' => (int) $ext['enabled'],
			'protected'        => (int) $ext['protected'] === 1,
			'locked'           => (int) $ext['locked'] === 1,
		]);
	}

	/** Why this extension may not be disabled, or null when it may. */
	private function disableRefusal(array $ext): ?string
	{
		$label = $ext['name'] . ' (' . $ext['type'] . ($ext['folder'] !== '' ? ' ' . $ext['folder'] : '') . ' / ' . $ext['element'] . ')';

		if ((int) $ext['protected'] === 1) {
			return 'Refusing to disable ' . $label . ': it is a protected core extension. Joomla doesn\'t allow disabling it either.';
		}

		foreach (self::KEEP_ENABLED as [$type, $folder, $element, $why]) {
			if ($ext['type'] === $type && (string) $ext['folder'] === $folder && $ext['element'] === $element) {
				return 'Refusing to disable ' . $label . ' through MCP: ' . $why . '. Disabling it would cut off '
					. 'this connection or the admin login. Do it in the Joomla admin if you really mean to.';
			}
		}

		if ($ext['type'] === 'template') {
			$styles = $this->db->getQuery(true)
				->select($this->db->quoteName(['template', 'home', 'parent']))
				->from($this->db->quoteName('#__template_styles'))
				->where($this->db->quoteName('client_id') . ' = ' . (int) $ext['client_id'])
				->where('(' . $this->db->quoteName('template') . ' = ' . $this->db->quote($ext['element'])
					. ' OR ' . $this->db->quoteName('parent') . ' = ' . $this->db->quote($ext['element']) . ')');

			foreach ((array) $this->db->setQuery($styles)->loadAssocList() as $style) {
				if ($style['template'] === $ext['element'] && (string) $style['home'] !== '0') {
					return 'Refusing to disable ' . $label . ': one of its styles is a default template style'
						. ((string) $style['home'] !== '1' ? ' (for ' . $style['home'] . ')' : '')
						. '. Make another template the default first (set_default_template_style).';
				}

				if ((string) $style['parent'] === $ext['element']) {
					return 'Refusing to disable ' . $label . ': child template ' . $style['template'] . ' depends on it.';
				}
			}
		}

		return null;
	}
}
