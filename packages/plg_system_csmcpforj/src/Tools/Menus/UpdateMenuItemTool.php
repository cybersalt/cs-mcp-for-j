<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\User\User;
use Joomla\Component\Menus\Administrator\Helper\MenusHelper;
use Joomla\Database\ParameterType;

/**
 * @partial-save-safe com_menus' ItemModel::save() and Table\Menu::bind() are NOT
 *   partial-payload safe; this tool backfills every key they read
 *   unconditionally from the stored row before saving. Reviewed against Joomla
 *   6.2.0, 2026-10-20:
 *   - ItemModel::save() reads parent_id, menuordering and menutype (#31) —
 *     re-supplied, menuordering 0 = "leave the position alone".
 *   - Table\Menu::bind() reads home, language and published to protect the
 *     default home page: with them absent, `null !== '*'` refused every update
 *     of the "All languages" home item ("cannot unset default"). Re-supplied.
 *   - ItemModel::save() rebuilds #__associations from $data['associations'];
 *     absent, it unlinked the item from its translations. Re-supplied.
 *   - params are merged onto the STORED blob, never onto getItem()'s copy
 *     (which has the link's request vars folded in).
 *   Nothing else on the row is touched unless the caller passes it; the model
 *   loads the row and binds only supplied keys.
 */
final class UpdateMenuItemTool extends AbstractTool
{
	use MenuItemFieldsTrait;

	private const UPDATABLE = [
		'title', 'alias', 'menutype', 'parent_id', 'link', 'published', 'language', 'access',
		'home', 'note', 'browserNav', 'template_style_id',
	];

	public function getName(): string { return 'update_menu_item'; }

	public function getDescription(): string
	{
		return 'Update a menu item. Required: id. Only the fields you pass change; everything else '
			. '(menu, parent, position, params, associations) is kept. Top-level fields: title, alias, '
			. 'note, menutype (moves the item and its children to another menu), parent_id, '
			. 'menuordering (-1 first, -2 last, or the id of a sibling to place it after), link, '
			. 'published (1, 0, -2 trash), publish_up / publish_down (UTC "YYYY-MM-DD HH:MM:SS", '
			. 'empty string clears), home, language, access, browserNav (target window), '
			. 'template_style_id (0 = default), associations ({"fr-FR": id} replaces the item\'s '
			. 'language associations, like the Associations tab). PARAMS: named args cover the '
			. 'core edit screen — Link Type tab: menu_anchor_title, menu_anchor_css, menu_anchor_rel, '
			. 'menu_icon_css, menu_image, menu_image_css, menu_text, menu_show; Page Display tab: '
			. 'browser_page_title, show_page_heading, page_heading, page_class_sfx; Metadata tab: '
			. 'meta_description, robots ("index, follow" etc.; clear with params_unset: ["robots"]); '
			. 'alias items: alias_target, alias_redirect. Every other params key (the component '
			. 'view\'s own options, e.g. show_subcat_image on a category view in Joomla 6.2, '
			. 'num_leading_articles, show_title) goes in params_set: an object merged into the '
			. 'existing params, where a null value deletes the key. params_unset: string[] also '
			. 'deletes keys. Named args win over params_set on collision. List-type view options '
			. 'store strings ("1", "0"); removing the key means "Use Global".';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => array_merge([
				'id'                => ['type' => 'integer'],
				'title'             => ['type' => 'string'],
				'alias'             => ['type' => 'string'],
				'note'              => ['type' => 'string'],
				'menutype'          => ['type' => 'string', 'description' => 'Move the item (and its children) to this menu. Omit to keep it where it is.'],
				'parent_id'         => ['type' => 'integer', 'description' => '1 = top level. A new parent puts the item last under it.'],
				'menuordering'      => ['type' => 'integer', 'description' => 'Position under its parent: -1 first, -2 last, or the id of a sibling to place it after. Ignored when parent_id changes (the item goes last). Omit to keep the current position.'],
				'link'              => ['type' => 'string'],
				'published'         => ['type' => 'integer', 'enum' => [0, 1, -2], 'description' => '1 published, 0 unpublished, -2 trashed.'],
				'publish_up'        => ['type' => 'string', 'description' => 'Start Publishing, UTC "YYYY-MM-DD HH:MM:SS". Empty string clears it.'],
				'publish_down'      => ['type' => 'string', 'description' => 'Finish Publishing, UTC "YYYY-MM-DD HH:MM:SS". Empty string clears it.'],
				'home'              => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 makes this the home page for its language (component items only).'],
				'language'          => ['type' => 'string', 'description' => 'Language tag, or "*" for all.'],
				'access'            => ['type' => 'integer', 'description' => 'Viewing access level id (list_access_levels).'],
				'browserNav'        => ['type' => 'integer', 'enum' => [0, 1, 2], 'description' => 'Target Window: 0 parent, 1 new window with navigation, 2 new window without navigation.'],
				'template_style_id' => ['type' => 'integer', 'description' => 'Template Style id (list_template_styles); 0 = use the default.'],
				'associations'      => ['type' => 'object', 'description' => 'Multilingual sites: language tag => menu item id for every translation of this item. Replaces the current set, like saving the Associations tab. Requires the item to have a specific language (not "*").'],
			], $this->menuNamedParamSchema(), [
				'params_set'   => ['type' => 'object', 'description' => 'Any params key => value, merged into the existing params. A null value deletes that key. Named args win on collision.'],
				'params_unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'params keys to delete (the "Use Global" state for list options).'],
			]),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_menus', 'Item');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Menu item ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}

		foreach (['publish_up', 'publish_down'] as $dateKey) {
			$date = $this->normalisePublishDate($arguments, $dateKey);
			if ($date !== null) {
				$data[$dateKey] = $date;
			}
		}

		/*
		 * com_menus' ItemModel::save() is NOT partial-payload safe. It reads
		 * three keys off $data unconditionally, before any bind:
		 *
		 *     if ($table->parent_id == $data['parent_id']) { ...
		 *     if ($data['menuordering'] == -1)             { ...
		 *     if ($data['menutype'] != $table->menutype)   { ...
		 *
		 * Omit them and PHP 8 evaluates each to null, so the model concludes the
		 * item is being moved to a menu called "" and stores it that way. The
		 * item survives in #__menu but belongs to no menu: it renders nowhere and
		 * disappears from the Menus manager, while still holding its alias — so
		 * the obvious recovery ("it's gone, I'll recreate it") then collides with
		 * that orphan and, before the guard below existed, died on
		 * ApiRouter::build() with an error naming neither cause.
		 *
		 * So: re-supply them from the row we already loaded whenever the caller
		 * did not. A caller who DOES pass menutype or parent_id still moves the
		 * item, which is the documented behaviour.
		 *
		 * menuordering 0 means "leave the position alone" — we never reorder on a
		 * field update unless the caller asked to.
		 */
		if (!array_key_exists('menutype', $data)) {
			$data['menutype'] = $existing->menutype;
		}
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing->parent_id;
		}
		$data['menuordering'] = 0;

		/*
		 * Table\Menu::bind() reads home, language and published straight off the
		 * payload to stop the "All languages" default home page from being
		 * unset, re-languaged or unpublished:
		 *
		 *     if ($this->home == '1' && $this->language === '*' && $array['language'] !== '*')
		 *
		 * With the key absent that is `null !== '*'`, so every partial update of
		 * the site's home item — even a title change — was refused with
		 * "cannot unset default". Re-supplying the stored values is a no-op for
		 * every other item: the table already holds them.
		 */
		if (!array_key_exists('home', $data)) {
			$data['home'] = (int) $existing->home;
		}
		if (!array_key_exists('language', $data)) {
			$data['language'] = (string) $existing->language;
		}
		if (!array_key_exists('published', $data)) {
			$data['published'] = (int) $existing->published;
		}

		if (array_key_exists('menuordering', $arguments)) {
			$ordering = (int) $arguments['menuordering'];

			if ($ordering < -2) {
				return ToolResult::error('menuordering must be -1 (first), -2 (last), or the id of a sibling menu item.');
			}

			if ((int) $data['parent_id'] === (int) $existing->parent_id && $ordering !== 0) {
				/*
				 * A positive value is "after this sibling". Joomla calls
				 * setLocation($ordering, 'after') with no check that it IS a
				 * sibling, so any other id silently re-parents the item next to
				 * that node — possibly into another menu's tree. Refuse that.
				 */
				if ($ordering > 0 && $ordering !== $id && !$this->isSibling($ordering, (int) $data['parent_id'], (int) $existing->client_id)) {
					return ToolResult::error('menuordering ' . $ordering . ' is not a sibling of menu item ' . $id
						. ' (parent ' . (int) $data['parent_id'] . '). Pass -1 (first), -2 (last), or the id of an item with the same parent.');
				}

				$data['menuordering'] = $ordering;
			}
		}

		/*
		 * Issue #18, second half: when the link changes, component_id must follow
		 * it. ItemModel::save() stores component_id exactly as given — only
		 * getItem() re-derives it from the link, and that runs when the ADMIN FORM
		 * loads, not on save. So changing a com_content link to com_contact over
		 * MCP left component_id pointing at the old component. The item then
		 * renders from one component while the manager reports another.
		 *
		 * Only for type `component`. A url/alias/heading/separator item has
		 * component_id 0 by definition, and a url item's link can legitimately
		 * carry an `option=` (an internal link typed by hand) — deriving an id
		 * from that would give a URL item a component it does not have. `type` is
		 * not updatable here, so the stored type is authoritative.
		 */
		if (
			array_key_exists('link', $data)
			&& (string) $existing->type === 'component'
			&& (string) $data['link'] !== (string) $existing->link
		) {
			$componentId = $this->componentIdForLink((string) $data['link']);

			if ($componentId > 0) {
				$data['component_id'] = $componentId;
			}
		}

		/*
		 * Refuse an alias clash ourselves: Table\Menu::store() reports one by
		 * calling Route::_(), which is fatal under the API application. Only
		 * worth checking when something that feeds the clash scope changes.
		 */
		if (array_key_exists('alias', $arguments) || array_key_exists('parent_id', $arguments) || array_key_exists('language', $arguments)) {
			$alias = array_key_exists('alias', $arguments) ? (string) $arguments['alias'] : (string) $existing->alias;
			$title = array_key_exists('title', $arguments) ? (string) $arguments['title'] : (string) $existing->title;
			$alias = $this->effectiveMenuAlias($alias, $title, (string) $data['language']);

			if ($clash = $this->findMenuAliasClash($alias, (int) $data['parent_id'], (int) $existing->client_id, (string) $data['language'], $id)) {
				return ToolResult::error($this->menuAliasClashMessage($clash, $alias, (int) $data['parent_id']));
			}
		}

		// Decide whether any params-touching arg was supplied. If not, skip
		// params handling entirely so the existing blob isn't re-serialised
		// (matches Joomla's own "don't touch what you didn't change" feel).
		$paramsTouched = isset($arguments['params_set']) || isset($arguments['params_unset']);
		foreach (self::menuNamedParams() as $publicArg => $_) {
			if (array_key_exists($publicArg, $arguments)) { $paramsTouched = true; break; }
		}

		$paramsTouchedKeys = [];

		if ($paramsTouched) {
			/*
			 * Merge onto the STORED params, read straight from #__menu — never
			 * onto $existing->params. ItemModel::getItem() builds that for the
			 * admin form: for component, alias and url items it array_merge()s
			 * the link's query vars into it ("all request arguments become
			 * reserved parameter names"), and for the Login item it injects
			 * login/logoutredirectchoice. The admin form keeps those in separate
			 * fieldsets so they never round-trip; merging onto them here wrote
			 * option/view/id into params on every params_set (seen on
			 * cybersalt.com 2026-10-16: an article link's params went from []
			 * to {"option":"com_content","view":"article","id":"844",...}).
			 */
			$merged = $this->mergeJsonPatch($this->storedParams($id), []);

			// 1. params_set (lowest precedence among new values); null deletes.
			if (isset($arguments['params_set']) && is_array($arguments['params_set'])) {
				if ($arguments['params_set'] !== [] && array_is_list($arguments['params_set'])) {
					return ToolResult::error('params_set must be an object of key/value pairs, not a list.');
				}
				$merged = $this->mergeJsonPatch($merged, $arguments['params_set']);
				foreach ($arguments['params_set'] as $k => $_) {
					$paramsTouchedKeys[(string) $k] = true;
				}
			}

			// 2. Named args (override params_set on collision)
			$merged = $this->applyMenuNamedParams($merged, $arguments, $paramsTouchedKeys);

			// 3. params_unset (highest precedence — explicit deletion)
			if (isset($arguments['params_unset']) && is_array($arguments['params_unset'])) {
				foreach ($arguments['params_unset'] as $k) {
					$key = (string) $k;
					unset($merged[$key]);
					$paramsTouchedKeys[$key] = true;
				}
			}

			if ((string) $existing->type === 'alias' && isset($merged['aliasoptions']) && (int) $merged['aliasoptions'] === $id) {
				return ToolResult::error('An alias menu item cannot point to itself (alias_target ' . $id . ').');
			}

			$data['params'] = $merged;
		}

		/*
		 * Deliberately NOT wrapped in a transaction, and please do not add one.
		 *
		 * It looks like the obvious guard for "the save failed halfway", but it
		 * cannot work here: Joomla\CMS\Table\Nested::store() calls _lock() on
		 * every update, which issues LOCK TABLES — and MySQL/MariaDB implicitly
		 * COMMIT any open transaction at LOCK TABLES. By the time save() returns
		 * false there is nothing left to roll back, so the code reads as a safety
		 * net while providing none. The same applies to #__categories and
		 * anything else on a nested set.
		 *
		 * What actually prevents the partial write is validating BEFORE the save:
		 * the menutype/parent_id re-supply above, and the component_id derivation,
		 * both run first precisely so the payload is already complete and correct
		 * when the model gets it.
		 */

		/*
		 * Language associations (multilingual sites). ItemModel::save() rebuilds
		 * #__associations from `$data['associations'] ?? []` on every save of a
		 * site menu item while associations are enabled: with the key missing,
		 * it deletes the item's whole association group and re-adds only the
		 * item itself, so one partial update unlinked the item from all its
		 * translations. Same pattern Susan found for tags and custom-field
		 * categories (2.9.0). Re-supply the current associations unless the
		 * caller is setting them.
		 */
		$assocEnabled = (int) $existing->client_id === 0 && Associations::isEnabled();

		if (array_key_exists('associations', $arguments)) {
			if (!$assocEnabled) {
				return ToolResult::error('associations can only be set on a site menu item while language associations are enabled (System - Language Filter plugin, "Item Associations").');
			}

			$associations = $this->normaliseAssociationsArg($arguments['associations']);

			if ($associations !== [] && (string) $data['language'] === '*') {
				return ToolResult::error('A menu item set to "All" languages cannot have associations. Set language to a specific tag first (it can be done in the same call).');
			}

			$data['associations'] = $associations;
		} elseif ($assocEnabled) {
			$data['associations'] = MenusHelper::getAssociations($id);
		}

		if (isset($data['associations']) && is_array($data['associations'])) {
			/*
			 * Never hand the item its own id. getAssociations() includes the item
			 * itself under its CURRENT language, and ItemModel::save() adds it again
			 * under the language being saved: on a language change that is two keys
			 * for one id, a duplicate key on #__associations, and save() fails after
			 * the old association group was already deleted. The admin form never
			 * posts the item's own id, so neither do we.
			 */
			$data['associations'] = array_filter($data['associations'], static fn ($v): bool => (int) $v !== $id);
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_menus rejected the update: ' . $model->getError());
		}

		$response = ['ok' => true, 'id' => $id];
		if ($paramsTouched) {
			$response['params_modified'] = array_keys($paramsTouchedKeys);
		}
		return ToolResult::json($response);
	}

	/** True when $candidateId is a menu item with the given parent (and client). */
	private function isSibling(int $candidateId, int $parentId, int $clientId): bool
	{
		$db = $this->db;
		$q  = $db->getQuery(true)
			->select('1')
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('id') . ' = :id')
			->where($db->quoteName('parent_id') . ' = :parent')
			->where($db->quoteName('client_id') . ' = :client')
			->bind(':id', $candidateId, ParameterType::INTEGER)
			->bind(':parent', $parentId, ParameterType::INTEGER)
			->bind(':client', $clientId, ParameterType::INTEGER);

		return (bool) $db->setQuery($q)->loadResult();
	}

	/**
	 * The item's params exactly as stored in #__menu, without the request
	 * vars getItem() folds in for the edit form.
	 */
	private function storedParams(int $id): string
	{
		$db = $this->db;
		$q  = $db->getQuery(true)
			->select($db->quoteName('params'))
			->from($db->quoteName('#__menu'))
			->where($db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		return (string) $db->setQuery($q)->loadResult();
	}
}
