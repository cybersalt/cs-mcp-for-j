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
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Changes a component's Options (the settings behind the Options button),
 * stored in #__extensions.params for type=component.
 *
 * Merge only: the patch goes through AbstractTool::mergeJsonPatch(), so only
 * the keys sent change, null deletes a key, and every other saved option is
 * kept. There is no "replace" mode on purpose: wiping a component's Options
 * by accident (e.g. com_users registration settings) is easy and quiet.
 *
 * Same write path as set_plugin_params: a direct UPDATE of the one
 * #__extensions row. Joomla's own Options screen goes through com_config's
 * ComponentModel, but that model depends on the admin request (form state,
 * component path, controller-side validation), which the API app does not
 * provide. What the model adds after the store is clearing the _system cache
 * group, where ComponentHelper caches every component's params; we do the same
 * for both the site and administrator caches.
 *
 * Differences from set_plugin_params, deliberately:
 * - `protected` / `locked` are not refused. Nearly every core component is
 *   protected (that only stops uninstalling/disabling it), and Joomla's
 *   Options screen edits them freely. Refusing would block com_content.
 * - `rules` is refused: permissions live in #__assets, not in params, and
 *   have their own Super-User tool (set_component_permission).
 * - com_config is refused: Global Configuration is configuration.php, and
 *   com_config's own row only carries the Text Filters, which are a security
 *   setting edited from Global Configuration.
 * - com_csmcpforj is refused: MCP for J should not change its own
 *   settings (read-only mode, OAuth, the Pro activation values) over MCP.
 */
final class SetComponentParamsTool extends AbstractTool
{
	use ComponentOptionsTrait;

	/** Components whose params this tool will not write. */
	private const REFUSED_COMPONENTS = [
		'com_config'    => 'Global Configuration is stored in configuration.php, not in component options, and com_config\'s own row holds only the Text Filters (a security setting). Change those in System > Global Configuration.',
		'com_csmcpforj' => 'MCP for J does not change its own settings over MCP (read-only mode, OAuth sign-in and the Pro activation values live there). Change them in Components > MCP for J > Options.',
	];

	public function getName(): string { return 'set_component_params'; }

	public function getDescription(): string
	{
		return 'Change a component\'s Options (the settings behind the Options button in the Joomla '
			. 'admin, e.g. for Articles, Contacts, Users, Media). Required: component (e.g. '
			. '"com_content") and params. Send only the options you want to change; every other '
			. 'saved option is kept. A null value removes the saved option so Joomla falls back to '
			. 'its default. A nested value (a multi-select or subform) is replaced whole. Call '
			. 'get_component_params first to see the option names, choices and current values. '
			. 'Permissions cannot be changed here (use set_component_permission). Global '
			. 'Configuration is not a component option and cannot be changed with this tool, and '
			. 'neither can MCP for J\'s own options. Super Users only.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['component', 'params'],
			'properties' => [
				'component' => [
					'type'        => 'string',
					'description' => 'Component name, e.g. "com_content", "com_contact", "com_users".',
				],
				'params' => [
					'type'        => 'object',
					'description' => 'The options to change, as name/value pairs, e.g. {"show_subcat_image": "1"}. Use the same kind of value the option already has; most Joomla options are saved as strings such as "1" and "0". null removes an option.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$actor->authorise('core.admin')) {
			return ToolResult::error(
				'set_component_params requires Super User (core.admin). The csmcpforj.write grant alone '
				. 'is not sufficient because component options include security settings (user registration '
				. 'and default groups, allowed upload file types, installer settings).'
			);
		}

		$component = $this->requireComponentName($arguments);

		if (isset(self::REFUSED_COMPONENTS[$component])) {
			return ToolResult::error('Refusing to change ' . $component . ': ' . self::REFUSED_COMPONENTS[$component]);
		}

		$patch = $arguments['params'] ?? null;

		if (!is_array($patch) || ($patch !== [] && array_is_list($patch))) {
			return ToolResult::error('params must be an object of option names and values, e.g. {"show_subcat_image": "1"}.');
		}

		if ($patch === []) {
			return ToolResult::error('params is empty: nothing to change. Pass the options to change, e.g. {"show_subcat_image": "1"}.');
		}

		foreach (array_keys($patch) as $key) {
			if (!is_string($key) || trim($key) === '') {
				return ToolResult::error('Every key in params must be an option name.');
			}
		}

		if (array_key_exists('rules', $patch)) {
			return ToolResult::error(
				'params must not contain "rules". Permissions are stored separately from component options; '
				. 'use list_component_permissions and set_component_permission to change them.'
			);
		}

		$row = $this->loadComponentRow($component);

		if ($row === null) {
			return ToolResult::error('Component not installed: ' . $component . '. Use list_extensions with type "component" to see what is installed.');
		}

		$stored = $this->decodeParams($row['params'] ?? null);
		$patch  = $this->matchStoredTypes($stored, $patch);
		$merged = $this->mergeJsonPatch($stored, $patch);

		$changed = [];
		$removed = [];
		$before  = [];

		foreach ($patch as $key => $value) {
			$before[$key] = $stored[$key] ?? null;

			if ($value === null) {
				$removed[] = $key;
			} else {
				$changed[] = $key;
			}
		}

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('params') . ' = ' . $this->db->quote(
				json_encode($merged ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
			))
			->where($this->db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);
		$this->db->setQuery($update)->execute();

		$result = [
			'ok'            => true,
			'extension_id'  => (int) $row['extension_id'],
			'component'     => $component,
			'changed_keys'  => $changed,
			'removed_keys'  => $removed,
			'before'        => $before,
			'params'        => $merged ?: new \stdClass(),
			'cache_cleared' => $this->clearSystemCache(),
		];

		// Warn (not refuse) about names the component does not declare: most
		// are typos, but components can keep their own undeclared values here.
		$definitions = $this->readConfigXmlOptions($component);

		if ($definitions !== null) {
			$declared = [];

			foreach ($definitions as $definition) {
				$declared[explode('.', (string) $definition['name'])[0]] = true;
			}

			$unknown = array_values(array_filter(
				array_keys($patch),
				static fn (string $key): bool => !isset($declared[$key])
			));

			if ($unknown) {
				$result['unknown_keys'] = $unknown;
				$result['warning']      = 'These option names are not declared in ' . $component . '\'s Options screen, so the '
					. 'component may ignore them: ' . implode(', ', $unknown) . '. They were saved anyway. Check the '
					. 'spelling with get_component_params, and send null for a key to remove it.';
			}
		}

		return ToolResult::json($result);
	}

	/**
	 * Joomla's Options screen posts form values, so most options are saved
	 * as strings ("1", "0", "10"). An AI tends to send 1 / true instead. Bring
	 * scalar values into line so code that compares strictly keeps working:
	 * true/false always become "1"/"0" (Joomla never saves booleans); numbers
	 * become strings unless the saved value is already a number (fields with
	 * filter="integer" save real integers).
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $patch
	 *
	 * @return array<string, mixed>
	 */
	private function matchStoredTypes(array $stored, array $patch): array
	{
		foreach ($patch as $key => $value) {
			if (is_bool($value)) {
				$patch[$key] = $value ? '1' : '0';
				continue;
			}

			if ((is_int($value) || is_float($value))
				&& !(array_key_exists($key, $stored) && (is_int($stored[$key]) || is_float($stored[$key])))) {
				$patch[$key] = (string) $value;
			}
		}

		return $patch;
	}

	/**
	 * Clear the _system cache group on both clients. ComponentHelper caches
	 * every component's params there, so with caching on a change would not
	 * show until the cache expired. Same group com_config's ComponentModel
	 * cleans after saving the Options screen.
	 */
	private function clearSystemCache(): bool
	{
		$ok = true;

		foreach ([JPATH_SITE . '/cache', JPATH_ADMINISTRATOR . '/cache'] as $cacheBase) {
			try {
				Factory::getContainer()->get(CacheControllerFactoryInterface::class)
					->createCacheController('callback', [
						'defaultgroup' => '_system',
						'cachebase'    => $cacheBase,
						'caching'      => true,
					])
					->clean();
			} catch (\Throwable $e) {
				$ok = false;
			}
		}

		return $ok;
	}
}
