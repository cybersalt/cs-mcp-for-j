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

/**
 * Create a menu item. Link per type, as Joomla's own Menu Item Type picker
 * stores it:
 *  - component   link="index.php?option=com_content&view=article&id=N"
 *  - url         link="https://..."
 *  - alias       link is always "index.php?Itemid=" — the target lives in
 *                params.aliasoptions (alias_target). A link of
 *                "index.php?Itemid=N" is still accepted and read as the target.
 *  - heading / separator   no link
 */
final class CreateMenuItemTool extends AbstractTool
{
	use MenuItemFieldsTrait;

	public function getName(): string { return 'create_menu_item'; }

	public function getDescription(): string
	{
		return 'Create a menu item. Required: title, menutype (use list_menus), type '
			. '(component|url|alias|heading|separator). type=component needs a link such as '
			. '"index.php?option=com_content&view=article&id=42" (list_menu_item_types shows the '
			. 'links); type=url needs link="https://..."; type=alias needs alias_target (the id of '
			. 'the menu item it points to). Optional: alias, note, parent_id (default 1 = top level), '
			. 'published (default 1), publish_up / publish_down (UTC "YYYY-MM-DD HH:MM:SS"), home, '
			. 'language (default "*"), access (default 1), browserNav (target window), '
			. 'template_style_id, associations ({"fr-FR": id}). PARAMS: the same named args as '
			. 'update_menu_item (menu_show, menu_text, menu_anchor_title, menu_anchor_css, '
			. 'menu_anchor_rel, menu_icon_css, menu_image, menu_image_css, browser_page_title, '
			. 'show_page_heading, page_heading, page_class_sfx, meta_description, robots, '
			. 'alias_redirect) plus params: an object with any other key, such as the component '
			. 'view\'s own options (e.g. {"show_subcat_image": "1"} on a Joomla 6.2 category view). '
			. 'Named args win over params on collision.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'menutype', 'type'],
			'properties' => array_merge([
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string', 'description' => 'Defaults to one made from the title.'],
				'menutype'    => ['type' => 'string'],
				'type'        => ['type' => 'string', 'enum' => ['component', 'url', 'alias', 'heading', 'separator']],
				'link'        => ['type' => 'string'],
				'parent_id'   => ['type' => 'integer', 'description' => 'Default 1 (top level). The item is placed last under its parent.'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1]],
				'publish_up'  => ['type' => 'string', 'description' => 'Start Publishing, UTC "YYYY-MM-DD HH:MM:SS".'],
				'publish_down' => ['type' => 'string', 'description' => 'Finish Publishing, UTC "YYYY-MM-DD HH:MM:SS".'],
				'home'        => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 to make this the home page (per language). Component items only.'],
				'language'    => ['type' => 'string', 'description' => 'Language tag, or "*" (default) for all.'],
				'access'      => ['type' => 'integer', 'description' => 'Viewing access level id (default 1, Public).'],
				'note'        => ['type' => 'string'],
				'browserNav'  => ['type' => 'integer', 'enum' => [0, 1, 2], 'description' => 'Target Window: 0 parent, 1 new window with navigation, 2 new window without navigation.'],
				'template_style_id' => ['type' => 'integer', 'description' => 'Template Style id; 0 (default) = use the default style.'],
				'associations' => ['type' => 'object', 'description' => 'Multilingual sites: language tag => id of the menu item that translates this one. Needs a specific language (not "*").'],
			], $this->menuNamedParamSchema(), [
				'params' => ['type' => 'object', 'description' => 'Initial params: any key => value (component view options, etc.). Named args win on collision.'],
			]),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title    = $this->requireString($arguments, 'title');
		$menutype = $this->requireString($arguments, 'menutype');
		$type     = $this->requireString($arguments, 'type');
		$language = trim((string) ($arguments['language'] ?? '')) ?: '*';
		$parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1;

		$clientId = $this->menuTypeClientId($menutype);
		if ($clientId === null) {
			return ToolResult::error('There is no menu "' . $menutype . '". Use list_menus for the menutype values.');
		}

		$alias = $this->effectiveMenuAlias((string) ($arguments['alias'] ?? ''), $title, $language);

		// Params: the initial object, then the named args on top.
		$touched = [];
		if (isset($arguments['params']) && is_array($arguments['params'])) {
			$this->refuseRetiredMenuParams($arguments['params']);
		}
		$params  = isset($arguments['params']) && is_array($arguments['params'])
			? $this->mergeJsonPatch([], $arguments['params'])
			: [];
		$params  = $this->applyMenuNamedParams($params, $arguments, $touched);

		// Map type to component / link
		$component_id = 0;
		$link         = trim((string) ($arguments['link'] ?? ''));

		switch ($type) {
			case 'component':
				if ($link === '') {
					return ToolResult::error('type=component needs a link, e.g. "index.php?option=com_content&view=article&id=42". list_menu_item_types lists them.');
				}
				$component_id = $this->componentIdForLink($link);
				if ($component_id <= 0) {
					return ToolResult::error('The link names no installed component (it needs option=com_...): ' . $link);
				}
				break;

			case 'url':
				if ($link === '') {
					return ToolResult::error('type=url needs a link, e.g. "https://example.com/".');
				}
				break;

			case 'alias':
				/*
				 * Joomla stores an alias item's link as the bare "index.php?Itemid="
				 * and reads the target from params.aliasoptions (SiteRouter,
				 * SiteApplication, Pathway). Earlier versions of this tool told
				 * callers to put the target in the link, which produced an alias
				 * item with no target; that form is still accepted.
				 */
				if (!isset($params['aliasoptions']) && $link !== '') {
					$query = [];
					parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
					if (!empty($query['Itemid'])) {
						$params['aliasoptions'] = (int) $query['Itemid'];
					}
				}
				if (empty($params['aliasoptions'])) {
					return ToolResult::error('type=alias needs alias_target: the id of the menu item this alias points to.');
				}
				$link = 'index.php?Itemid=';
				break;

			case 'heading':
			case 'separator':
				$link = '';
				break;

			default:
				return ToolResult::error('type must be one of component, url, alias, heading, separator.');
		}

		$data = [
			'id'                => 0,
			'menutype'          => $menutype,
			'title'             => $title,
			'alias'             => $alias,
			'note'              => (string) ($arguments['note'] ?? ''),
			'link'              => $link,
			'type'              => $type,
			'published'         => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'parent_id'         => $parentId,
			'level'             => 1,
			'component_id'      => $component_id,
			'access'            => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'          => $language,
			'home'              => isset($arguments['home']) ? (int) $arguments['home'] : 0,
			'browserNav'        => isset($arguments['browserNav']) ? (int) $arguments['browserNav'] : 0,
			'template_style_id' => isset($arguments['template_style_id']) ? (int) $arguments['template_style_id'] : 0,
			'params'            => $params === [] ? '{}' : $params,
			'client_id'         => $clientId,
		];

		foreach (['publish_up', 'publish_down'] as $dateKey) {
			$date = $this->normalisePublishDate($arguments, $dateKey);
			if ($date !== null && $date !== '') {
				$data[$dateKey] = $date;
			}
		}

		if (array_key_exists('associations', $arguments)) {
			if ($clientId !== 0 || !Associations::isEnabled()) {
				return ToolResult::error('associations can only be set on a site menu item while language associations are enabled (System - Language Filter plugin, "Item Associations").');
			}

			$associations = $this->normaliseAssociationsArg($arguments['associations']);

			if ($associations !== [] && $language === '*') {
				return ToolResult::error('A menu item set to "All" languages cannot have associations. Pass a specific language.');
			}

			$data['associations'] = $associations;
		}

		/*
		 * Refuse an alias collision ourselves, because letting Joomla detect it
		 * is fatal under the API application (see findMenuAliasClash()). Scoped
		 * the way Table\Menu::store() scopes it — (parent, client, alias), not
		 * the menu — so a top-level alias already used in ANOTHER menu is caught
		 * here too. Earlier versions scoped by menu and let that case through to
		 * the ApiRouter::build() fatal.
		 */
		if ($clash = $this->findMenuAliasClash($alias, $parentId, $clientId, $language, 0)) {
			return ToolResult::error($this->menuAliasClashMessage($clash, $alias, $parentId));
		}

		$model  = $this->getModel('com_menus', 'Item');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('com_menus rejected the menu item: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $title, 'alias' => $alias, 'menutype' => $menutype];
		if ($params !== []) {
			$response['params_keys'] = array_keys($params);
		}
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
