<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Multilanguage;
use Joomla\Database\ParameterType;

/**
 * What create_menu_item and update_menu_item share: the named params that
 * map onto Joomla 6.2's menu item edit screen (Link Type, Page Display,
 * Metadata, and the Alias item's target), and the checks both tools must
 * run before handing a payload to com_menus' ItemModel.
 *
 * Field names and option values come from Joomla 6.2.0's
 * administrator/components/com_menus/forms/item_component.xml,
 * item_url.xml, item_alias.xml, item_heading.xml and item_separator.xml.
 */
trait MenuItemFieldsTrait
{
	/**
	 * Public arg name => #__menu.params key. Named args are the safe path:
	 * they map to the keys Joomla's own admin form writes, so the agent
	 * can't typo the Joomla key (menu-meta_description vs meta_description,
	 * menu-anchor_css vs menu_anchor_css). Anything not in this map, such as
	 * a component view's own options (show_subcat_image, num_leading_articles,
	 * show_title...), is reachable via params / params_set.
	 *
	 * @return array<string,string>
	 */
	protected static function menuNamedParams(): array
	{
		return [
			// Page Display tab
			'browser_page_title' => 'page_title',
			'show_page_heading'  => 'show_page_heading',
			'page_heading'       => 'page_heading',
			'page_class_sfx'     => 'pageclass_sfx',
			// Metadata tab
			'meta_description'   => 'menu-meta_description',
			'robots'             => 'robots',
			// Link Type tab
			'menu_anchor_title'  => 'menu-anchor_title',
			'menu_anchor_css'    => 'menu-anchor_css',
			'menu_anchor_rel'    => 'menu-anchor_rel',
			'menu_icon_css'      => 'menu_icon_css',
			'menu_image'         => 'menu_image',
			'menu_image_css'     => 'menu_image_css',
			'menu_text'          => 'menu_text',
			'menu_show'          => 'menu_show',
			// Alias items (type=alias)
			'alias_target'       => 'aliasoptions',
			'alias_redirect'     => 'alias_redirect',
		];
	}

	/**
	 * No empty-string member, deliberately: Gemini rejects the whole tool
	 * catalogue at parse time if any enum contains "" (cs-mcp-for-j#29).
	 * "Use Global" is reached by removing the key (params_unset: ["robots"]).
	 *
	 * The values carry the space after the comma because that is exactly
	 * what Joomla's form options are ("index, follow"). The list field
	 * validates against those options, so a value stored without the space
	 * matched no option: the edit screen showed "Use Global" and the next
	 * save from the admin silently dropped the directive. The comma-only
	 * spelling earlier versions advertised is still accepted and normalised.
	 */
	protected static function menuRobotsValues(): array
	{
		return ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];
	}

	/** rel values offered by item_url.xml (minus its empty "None" option). */
	protected static function menuAnchorRelValues(): array
	{
		return [
			'alternate', 'author', 'bookmark', 'external', 'help', 'license', 'me', 'next',
			'nofollow', 'noopener', 'noreferrer', 'prefetch', 'prev', 'search', 'sponsored',
			'tag', 'ugc',
		];
	}

	/**
	 * JSON-schema properties for the named params.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function menuNamedParamSchema(): array
	{
		return [
			'browser_page_title' => ['type' => 'string', 'description' => 'params.page_title (Page Display > Browser Page Title): overrides the <title> tag for this page.'],
			'show_page_heading'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'params.show_page_heading (Page Display > Show Page Heading). Remove the key with params_unset to go back to "Use Global".'],
			'page_heading'       => ['type' => 'string', 'description' => 'params.page_heading (Page Display > Page Heading): H1 override.'],
			'page_class_sfx'     => ['type' => 'string', 'description' => 'params.pageclass_sfx (Page Display > Page Class): CSS class added to the page.'],
			'meta_description'   => ['type' => 'string', 'description' => 'params.menu-meta_description (Metadata > Meta Description).'],
			'robots'             => ['type' => 'string', 'enum' => self::menuRobotsValues(), 'description' => 'params.robots (Metadata > Robots). To go back to "Use Global" use params_unset: ["robots"].'],
			'menu_anchor_title'  => ['type' => 'string', 'description' => 'params.menu-anchor_title (Link Type > Link Title Attribute): <a title="...">.'],
			'menu_anchor_css'    => ['type' => 'string', 'description' => 'params.menu-anchor_css (Link Type > Link Class): CSS class on the <a>.'],
			'menu_anchor_rel'    => ['type' => 'string', 'enum' => self::menuAnchorRelValues(), 'description' => 'params.menu-anchor_rel (Link Type > Link rel Attribute). URL items only. To clear use params_unset: ["menu-anchor_rel"].'],
			'menu_icon_css'      => ['type' => 'string', 'description' => 'params.menu_icon_css (Link Type > Link Icon Class), e.g. "fa-solid fa-house".'],
			'menu_image'         => ['type' => 'string', 'description' => 'params.menu_image (Link Type > Link Image): image path relative to the site root, e.g. "images/icons/home.png".'],
			'menu_image_css'     => ['type' => 'string', 'description' => 'params.menu_image_css (Link Type > Image Class).'],
			'menu_text'          => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'params.menu_text (Link Type > Add Menu Title): 0 shows only the image.'],
			'menu_show'          => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'params.menu_show (Link Type > Display in Menu): 0 hides the item from menu modules while the page still works.'],
			'alias_target'       => ['type' => 'integer', 'description' => 'params.aliasoptions (type=alias only): id of the menu item this alias points to.'],
			'alias_redirect'     => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'params.alias_redirect (type=alias only): 1 sends a redirect to the target instead of rendering it under this URL.'],
		];
	}

	/**
	 * Fold the named params in $arguments onto $params, normalising the
	 * values Joomla's form would have normalised.
	 *
	 * @param array<string,mixed>  $params   Params to write onto (merged result so far).
	 * @param array<string,bool>   $touched  Collects the Joomla keys written.
	 *
	 * @return array<string,mixed>
	 */
	protected function applyMenuNamedParams(array $params, array $arguments, array &$touched): array
	{
		foreach (self::menuNamedParams() as $publicArg => $jKey) {
			if (!array_key_exists($publicArg, $arguments)) {
				continue;
			}

			$value = $arguments[$publicArg];

			if (in_array($publicArg, ['show_page_heading', 'menu_text', 'menu_show', 'alias_redirect', 'alias_target'], true)) {
				$value = (int) $value;
			}

			$params[$jKey]  = $value;
			$touched[$jKey] = true;
		}

		if (isset($params['robots']) && is_string($params['robots'])) {
			$params['robots'] = $this->normaliseMenuRobots($params['robots']);
		}

		return $params;
	}

	/**
	 * Params the Joomla 6 menu item screen no longer shows, which MCP for J
	 * will not set (2.10.0, field review Q11, Tim 2026-10-28):
	 *   - secure: mod_menu still obeys it (MenuHelper builds the link with
	 *     it), so a wrong value could quietly force links to plain http with
	 *     nothing visible in the admin.
	 *   - menu-meta_keywords: only com_contact's form view reads it, and
	 *     search engines ignore meta keywords.
	 * Removing them (null / params_unset) is still allowed. Stored values are
	 * otherwise left alone.
	 *
	 * @param array<string,mixed> $params The caller's params / params_set.
	 */
	protected function refuseRetiredMenuParams(array $params): void
	{
		foreach (['secure', 'menu-meta_keywords'] as $key) {
			if (array_key_exists($key, $params) && $params[$key] !== null) {
				throw new \InvalidArgumentException(
					'params.' . $key . ' is not on the Joomla 6 menu item screen, so MCP for J does not set it. '
					. 'Use null (or params_unset) to remove a stored value.'
				);
			}
		}
	}

	/** "noindex,follow" / "NOINDEX ,  FOLLOW" => "noindex, follow" (Joomla's option value). */
	protected function normaliseMenuRobots(string $value): string
	{
		$parts = array_map('trim', explode(',', strtolower($value)));

		if (count($parts) === 2) {
			$candidate = $parts[0] . ', ' . $parts[1];

			if (in_array($candidate, self::menuRobotsValues(), true)) {
				return $candidate;
			}
		}

		return $value;
	}

	/**
	 * Validate and normalise a publish_up / publish_down argument.
	 *
	 * Returns null when the caller did not supply the key, '' to clear the
	 * date (Table\Menu::check() turns any empty value into NULL), or a
	 * 'Y-m-d H:i:s' UTC string. Joomla's form converts from the editor's
	 * timezone with filter="user_utc"; MCP has no form, so the value is taken
	 * as UTC, which is how the column is stored.
	 */
	protected function normalisePublishDate(array $arguments, string $key): ?string
	{
		if (!array_key_exists($key, $arguments)) {
			return null;
		}

		$raw = $arguments[$key];

		if ($raw === null || trim((string) $raw) === '') {
			return '';
		}

		try {
			return (new Date((string) $raw, 'UTC'))->toSql();
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException($key . ' must be a date/time such as "2026-11-01 09:00:00" (UTC), or an empty string to clear it.');
		}
	}

	/**
	 * Language tag => item id, as ItemModel::save() expects $data['associations'].
	 *
	 * @return array<string,int>
	 */
	protected function normaliseAssociationsArg(mixed $raw): array
	{
		if (!is_array($raw)) {
			throw new \InvalidArgumentException('associations must be an object of language tag => menu item id, e.g. {"fr-FR": 123}.');
		}

		$out = [];

		foreach ($raw as $tag => $id) {
			$tag = trim((string) $tag);

			if ($tag === '' || $tag === '*') {
				throw new \InvalidArgumentException('associations keys must be language tags such as "fr-FR".');
			}

			$out[$tag] = (int) $id;
		}

		return $out;
	}

	/** client_id of a menu (0 site, 1 administrator), or null if there is no such menu. */
	protected function menuTypeClientId(string $menutype): ?int
	{
		$db = $this->db;
		$q  = $db->getQuery(true)
			->select($db->quoteName('client_id'))
			->from($db->quoteName('#__menu_types'))
			->where($db->quoteName('menutype') . ' = :menutype')
			->bind(':menutype', $menutype);

		$value = $db->setQuery($q)->loadResult();

		return $value === null ? null : (int) $value;
	}

	/**
	 * Resolve `option=` in a menu link to its #__extensions.extension_id.
	 *
	 * Returns 0 when the link carries no option, or names a component that is
	 * not installed.
	 */
	protected function componentIdForLink(string $link): int
	{
		$query = [];
		parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

		$option = isset($query['option']) ? trim((string) $query['option']) : '';

		if ($option === '') {
			return 0;
		}

		$db = $this->db;
		$q  = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = :element')
			->bind(':element', $option);

		return (int) $db->setQuery($q)->loadResult();
	}

	/** The alias Table\Menu::store() will actually save. */
	protected function effectiveMenuAlias(string $alias, string $title, string $language): string
	{
		$alias = trim($alias) !== '' ? trim($alias) : trim($title);

		return ApplicationHelper::stringURLSafe($alias, $language);
	}

	/**
	 * Find the menu item Table\Menu::store() would report as an alias clash,
	 * so the tool can refuse first.
	 *
	 * Letting Joomla detect it is fatal under the API application: to build
	 * the "edit the conflicting item" link in its error message, store()
	 * calls Route::_(), which resolves to ApiRouter, which has no build(). The
	 * request dies with "Call to undefined method ApiRouter::build()", naming
	 * neither the alias nor the conflict.
	 *
	 * The scope mirrors store() in Joomla 6.2: (alias, parent_id, client_id),
	 * NOT menutype, so two top-level items in different menus collide. On a
	 * multilingual site only "All" and same-language items collide, except
	 * that a new "All" item collides with every language and an edited "All"
	 * item is also checked against every top-level item. Trashed items count,
	 * which is the case that wastes the most time because the Menus manager
	 * hides them.
	 *
	 * @return array{id:int,title:string,published:int,menutype:string}|null
	 */
	protected function findMenuAliasClash(string $alias, int $parentId, int $clientId, string $language, int $selfId): ?array
	{
		$db = $this->db;

		$base = function () use ($db, $alias, $clientId, $selfId) {
			return $db->getQuery(true)
				->select($db->quoteName(['id', 'title', 'published', 'menutype']))
				->from($db->quoteName('#__menu'))
				->where($db->quoteName('alias') . ' = :alias')
				->where($db->quoteName('client_id') . ' = :client')
				->where($db->quoteName('id') . ' != :self')
				->bind(':alias', $alias)
				->bind(':client', $clientId, ParameterType::INTEGER)
				->bind(':self', $selfId, ParameterType::INTEGER);
		};

		$q = $base()
			->where($db->quoteName('parent_id') . ' = :parent')
			->bind(':parent', $parentId, ParameterType::INTEGER);

		$multilingual = $clientId === 0 && Multilanguage::isEnabled();

		if ($multilingual && !($language === '*' && $selfId === 0)) {
			$q->where('(' . $db->quoteName('language') . ' = ' . $db->quote('*')
				. ' OR ' . $db->quoteName('language') . ' = :lang)')
				->bind(':lang', $language);
		}

		$row = $db->setQuery($q, 0, 1)->loadAssoc();

		if (!$row && $multilingual && $language === '*' && $selfId !== 0) {
			$q2  = $base()->where($db->quoteName('parent_id') . ' = 1');
			$row = $db->setQuery($q2, 0, 1)->loadAssoc();
		}

		return $row ?: null;
	}

	/** The error text for a clash found by findMenuAliasClash(). */
	protected function menuAliasClashMessage(array $clash, string $alias, int $parentId): string
	{
		$state = (int) $clash['published'] === -2
			? 'in the TRASH (which is why you cannot see it in the Menus manager — '
				. 'empty the menu trash, or pick a different alias)'
			: 'published/unpublished but present';

		return 'A menu item with alias "' . $alias . '" already exists under parent ' . $parentId
			. ' (menu "' . $clash['menutype'] . '"): id ' . (int) $clash['id'] . ', titled "' . $clash['title']
			. '", currently ' . $state . '. Joomla requires aliases to be unique among items with the same '
			. 'parent, and every top-level item in every site menu shares parent 1. Either choose a '
			. 'different alias, or edit item ' . (int) $clash['id'] . ' instead.';
	}
}
