<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * List every available menu item type installed on this Joomla site.
 *
 * Joomla menu items don't have a fixed set of types — every installed
 * component contributes its own (Single Article, Category List, Login Form,
 * Contact Form, Tag List, Custom URL, etc.). The exact list depends on which
 * components are installed. This tool is the "discover before create" path
 * an AI client needs before calling create_menu_item: each type has a
 * different required-fields shape (`request[id]` for a Single Article,
 * `request[catid]` for a Category List, etc.), so guessing type names that
 * don't exist on this site is a common failure mode without it.
 *
 * Sources read:
 *   - {component}/administrator/components/com_*\/views\/.../tmpl/default.xml
 *     The metadata.layout descriptor (Joomla's convention) declares
 *     a `<menuitem>` with a unique component-relative type code.
 *   - #__menu_types  (named menu containers — surfaced by list_menus,
 *     not duplicated here)
 *
 * Output is grouped by component so the AI can scope its create_menu_item
 * call to the right component-and-type pair.
 */
final class ListMenuItemTypesTool extends AbstractTool
{
	public function getName(): string { return 'list_menu_item_types'; }

	public function getDescription(): string
	{
		return 'List every menu item type installed on this Joomla site, grouped by component. '
			. 'Each entry shows the type code (used as `request[option]`+`request[view]` when '
			. 'calling create_menu_item), the component, the view, and the human title. Call this '
			. 'before create_menu_item so you pass a type the site actually has installed; the '
			. 'set varies by which components are present (Single Article, Category Blog, Login '
			. 'Form, Contact Form, Tag List, plus any third-party component\'s types).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'component' => ['type' => 'string', 'description' => 'Optional. Filter to a single component, e.g. com_content, com_users, com_contact.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$filter = trim((string) ($arguments['component'] ?? ''));
		if ($filter !== '' && preg_match('/^com_[a-z0-9_]+$/', $filter) !== 1) {
			return ToolResult::error('component must look like com_<name> if supplied.');
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$lang = Factory::getApplication()->getLanguage();

		// Find every installed enabled component — that's the source of menu item types.
		$query = $db->getQuery(true)
			->select($db->quoteName(['element']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('enabled') . ' = 1');
		if ($filter !== '') {
			$query->where($db->quoteName('element') . ' = ' . $db->quote($filter));
		}
		$components = $db->setQuery($query)->loadColumn() ?: [];

		$types = [];

		foreach ($components as $component) {
			$base = JPATH_ADMINISTRATOR . '/components/' . $component;
			if (!is_dir($base)) {
				continue;
			}

			// Load the component's own admin language so getText() resolves the
			// titles for any menu items it declares.
			$lang->load($component . '.sys', $base, null, false, true);
			$lang->load($component, $base, null, false, true);

			$views = $this->discoverViewsWithMenuMetadata($base);

			foreach ($views as $view => $layouts) {
				foreach ($layouts as $layout) {
					$types[] = [
						'component'   => $component,
						'view'        => $view,
						'layout'      => $layout['layout'],
						'type'        => $component . '.' . $view . ($layout['layout'] !== 'default' ? '.' . $layout['layout'] : ''),
						'title'       => Text::_((string) $layout['title']),
						'description' => $layout['description'] !== '' ? Text::_((string) $layout['description']) : '',
					];
				}
			}
		}

		usort($types, static fn($a, $b): int => strcmp($a['type'], $b['type']));

		return ToolResult::json([
			'total' => count($types),
			'types' => $types,
		]);
	}

	/**
	 * Walks the component's admin tmpl/<view>/<layout>.xml files looking for
	 * the standard `<menuitem>` metadata descriptor Joomla uses to declare a
	 * view as a valid menu item type. Returns a per-view array of
	 * [layout, title, description] entries.
	 *
	 * @return array<string, array<int, array{layout: string, title: string, description: string}>>
	 */
	private function discoverViewsWithMenuMetadata(string $base): array
	{
		$out = [];

		foreach (['/tmpl', '/src/View'] as $candidate) {
			$root = $base . $candidate;
			if (!is_dir($root)) {
				continue;
			}

			$xmls = glob($root . '/*/*.xml') ?: [];
			foreach ($xmls as $xmlPath) {
				// Normalize separators for the explode() below — native PHP,
				// no Joomla\CMS\Filesystem\Path (removed in J6).
				$cleanPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $xmlPath);
				$xml       = @simplexml_load_file($cleanPath);
				if ($xml === false || !isset($xml->layout)) {
					continue;
				}

				$layoutAttr = (string) $xml->layout['title'];
				$descAttr   = (string) ($xml->layout['description'] ?? '');
				if ($layoutAttr === '') {
					continue;
				}

				// Path shape: .../tmpl/<view>/<layout>.xml
				$parts = explode(DIRECTORY_SEPARATOR, $cleanPath);
				$count = count($parts);
				if ($count < 3) {
					continue;
				}
				$layoutName = pathinfo($parts[$count - 1], PATHINFO_FILENAME);
				$view       = $parts[$count - 2];

				$out[$view][] = [
					'layout'      => $layoutName,
					'title'       => $layoutAttr,
					'description' => $descAttr,
				];
			}
		}

		return $out;
	}
}
