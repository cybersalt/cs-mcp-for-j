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
use Joomla\CMS\User\User;

/**
 * Reads a component's Options (the settings behind the Options button in the
 * admin toolbar), stored in #__extensions.params for type=component.
 *
 * Direct DB read, NOT ComponentHelper::getParams(), for the same reason as
 * get_plugin_params: ComponentHelper keeps a per-request static copy and a
 * _system cache copy, so a read straight after set_component_params in the
 * same conversation could come back stale.
 *
 * Also lists the options the component declares in its config.xml, so the
 * AI knows which keys exist, what they mean and what Joomla falls back to
 * when a key has never been saved (common straight after a Joomla update adds
 * a new option, e.g. show_subcat_image in Joomla 6.2).
 *
 * Global Configuration is not here: it lives in configuration.php.
 */
final class GetComponentParamsTool extends AbstractTool
{
	use ComponentOptionsTrait;

	public function getName(): string { return 'get_component_params'; }

	public function getDescription(): string
	{
		return 'Read a component\'s Options (the settings behind the Options button in the Joomla '
			. 'admin, e.g. for Articles, Contacts, Users, Media). Required: component (e.g. '
			. '"com_content"). Returns the saved settings, plus the options the component declares '
			. '(name, label, type, default and choices) and which of them have never been saved, '
			. 'so Joomla is using the default. Needs the right to open the Options screen of that component ("Configure ACL & Options", or Super User). Permissions are not included; use '
			. 'list_component_permissions for those. Global Configuration is not a component option '
			. 'and is not available here. Pair with set_component_params to change settings. Use '
			. 'list_extensions (type component) to find component names.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['component'],
			'properties' => [
				'component' => [
					'type'        => 'string',
					'description' => 'Component name, e.g. "com_content", "com_contact", "com_users".',
				],
				'include_definitions' => [
					'type'        => 'boolean',
					'description' => 'Also list the options the component declares (name, label, type, default, choices). Default true. Set false for a shorter reply.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = $this->requireComponentName($arguments);

		/*
		 * Component Options can hold secrets (Akeeba's front-end secret word,
		 * payment and API keys, MCP for J's own Pro activation values), so
		 * reading them needs what Joomla's own Options screen needs: core.options
		 * on that component (core.admin passes too). The MCP "use" grant alone
		 * is not enough. MCP for J's own settings are not readable at all.
		 */
		if ($component === 'com_csmcpforj') {
			return ToolResult::error('MCP for J does not expose its own settings over MCP. See Components > MCP for J > Options.');
		}

		if (!$actor->authorise('core.admin') && !$actor->authorise('core.options', $component)) {
			return ToolResult::error('Reading the Options of ' . $component . ' needs the same right as its Options screen in the Joomla admin ("Configure ACL & Options" on that component, or Super User). Component Options can hold secrets such as API keys.');
		}

		$row       = $this->loadComponentRow($component);

		if ($row === null) {
			return ToolResult::error('Component not installed: ' . $component . '. Use list_extensions with type "component" to see what is installed.');
		}

		$params = $this->decodeParams($row['params'] ?? null);

		$result = [
			'extension_id' => (int) $row['extension_id'],
			'component'    => $component,
			'name'         => $row['name'],
			'enabled'      => (int) $row['enabled'] === 1,
			'params'       => $params ?: new \stdClass(),
		];

		if ($component === 'com_config') {
			$result['note'] = 'com_config\'s own row holds only the Text Filters set on the Global Configuration screen. '
				. 'The rest of Global Configuration lives in configuration.php and is not available through this tool.';
		}

		if (($arguments['include_definitions'] ?? true) !== false) {
			$definitions = $this->readConfigXmlOptions($component);

			if ($definitions === null) {
				$result['definitions'] = [];
				$result['definitions_note'] = 'This component has no config.xml, so it has no Options screen. Any saved params were written by the component itself.';
			} else {
				$result['definitions'] = $definitions;

				$notSaved = [];

				foreach ($definitions as $definition) {
					$topKey = explode('.', (string) $definition['name'])[0];

					if (!array_key_exists($topKey, $params)) {
						$notSaved[] = $definition['name'];
					}
				}

				$result['not_saved'] = array_values(array_unique($notSaved));
			}
		}

		return ToolResult::json($result);
	}
}
