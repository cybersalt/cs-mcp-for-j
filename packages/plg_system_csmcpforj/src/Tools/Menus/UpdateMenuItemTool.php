<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

final class UpdateMenuItemTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'alias', 'menutype', 'parent_id', 'link', 'published', 'language', 'access', 'home', 'note', 'browserNav', 'template_style_id'];

	/**
	 * Public arg name → Joomla #__menu.params key. Named args are the safe
	 * path: they map to the keys Joomla's own admin form writes, and the
	 * agent can't typo the Joomla key (e.g. menu-meta_description vs
	 * meta_description). Anything not in this map is reachable via
	 * params_set.
	 */
	private const NAMED_PARAMS = [
		'browser_page_title' => 'page_title',
		'meta_description'   => 'menu-meta_description',
		'meta_keywords'      => 'menu-meta_keywords',
		'robots'             => 'robots',
		'page_heading'       => 'page_heading',
		'show_page_heading'  => 'show_page_heading',
		'page_class_sfx'     => 'pageclass_sfx',
		'menu_anchor_title'  => 'menu-anchor_title',
		'secure'             => 'secure',
	];

	/**
	 * No empty-string member, deliberately. Google's Gemini API validates tool
	 * schemas against a strict OpenAPI subset and rejects the ENTIRE catalogue
	 * at parse time if any enum contains "" — HTTP 400 before a prompt even
	 * runs, so no Gemini-backed client could use this server at all. Anthropic
	 * tolerates it, which is why it went unnoticed.
	 *
	 * Nothing is lost: this constant is advertised in the schema only and is
	 * never validated against at run time, and clearing the directive already
	 * has a better route — params_unset: ["robots"] removes the key outright
	 * rather than storing an empty string.
	 *
	 * Reported 2026-09-16 by Mathew John via Cline/gemini-2.5-flash. See #29.
	 */
	private const VALID_ROBOTS = ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];

	public function getName(): string { return 'update_menu_item'; }

	public function getDescription(): string
	{
		return 'Update a menu item. Required: id. Top-level columns: title, alias, menutype, '
			. 'parent_id, link, published, home, language, access, note, browserNav, '
			. 'template_style_id. PARAMS (the JSON blob templates and SEO plugins read): use '
			. 'named args browser_page_title (the <title> tag override), meta_description, '
			. 'meta_keywords, robots ("index,follow", "noindex,follow", "index,nofollow", '
			. '"noindex,nofollow" — to clear it use params_unset: ["robots"]), '
			. 'page_heading, show_page_heading, page_class_sfx, '
			. 'menu_anchor_title, secure (0=off, 1=HTTPS, 2=HTTP). For any params key NOT in '
			. 'the named list, use params_set: object — merged into the existing params. To '
			. 'delete a key, list it in params_unset: string[]. Existing params keys you don\'t '
			. 'touch are preserved byte-for-byte. Named args take precedence over params_set on '
			. 'collision.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                => ['type' => 'integer'],
				'title'             => ['type' => 'string'],
				'alias'             => ['type' => 'string'],
				'menutype'          => ['type' => 'string'],
				'parent_id'         => ['type' => 'integer'],
				'link'              => ['type' => 'string'],
				'published'         => ['type' => 'integer', 'enum' => [0, 1]],
				'home'              => ['type' => 'integer', 'enum' => [0, 1]],
				'language'          => ['type' => 'string'],
				'access'            => ['type' => 'integer'],
				'note'              => ['type' => 'string'],
				'browserNav'        => ['type' => 'integer', 'enum' => [0, 1, 2]],
				'template_style_id' => ['type' => 'integer'],

				// Named SEO params — write into the row's `params` blob.
				'browser_page_title' => ['type' => 'string', 'description' => 'params.page_title — overrides the <title> tag for this page.'],
				'meta_description'   => ['type' => 'string', 'description' => 'params.menu-meta_description — overrides <meta name="description">.'],
				'meta_keywords'      => ['type' => 'string', 'description' => 'params.menu-meta_keywords — meta keywords (Bing-only, low SEO value).'],
				'robots'             => ['type' => 'string', 'enum' => self::VALID_ROBOTS, 'description' => 'params.robots — meta robots directive.'],
				'page_heading'       => ['type' => 'string', 'description' => 'params.page_heading — H1 override.'],
				'show_page_heading'  => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'params.show_page_heading — whether to render the heading.'],
				'page_class_sfx'     => ['type' => 'string', 'description' => 'params.pageclass_sfx — body class suffix.'],
				'menu_anchor_title'  => ['type' => 'string', 'description' => 'params.menu-anchor_title — <a title="..."> tooltip on the menu link.'],
				'secure'             => ['type' => 'integer', 'enum' => [0, 1, 2], 'description' => 'params.secure — 0 off, 1 force HTTPS, 2 force HTTP.'],

				// Escape hatches for keys not in NAMED_PARAMS.
				'params_set'   => ['type' => 'object', 'description' => 'Arbitrary key/value pairs merged into the existing params blob. Named args win on collision.'],
				'params_unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Keys to delete from the params blob.'],
			],
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
		 * field update, because the caller did not ask us to.
		 */
		if (!array_key_exists('menutype', $data)) {
			$data['menutype'] = $existing->menutype;
		}
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing->parent_id;
		}
		$data['menuordering'] = 0;

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

		// Decide whether any params-touching arg was supplied. If not, skip
		// params handling entirely so the existing blob isn't re-serialised
		// (matches Joomla's own "don't touch what you didn't change" feel).
		$paramsTouched = false;
		foreach (self::NAMED_PARAMS as $publicArg => $_) {
			if (array_key_exists($publicArg, $arguments)) { $paramsTouched = true; break; }
		}
		if (!$paramsTouched && (isset($arguments['params_set']) || isset($arguments['params_unset']))) {
			$paramsTouched = true;
		}

		$paramsTouchedKeys = [];

		if ($paramsTouched) {
			// Existing params can arrive as a Registry, an object, a JSON string,
			// or an array depending on which Joomla code path filled it in.
			// Normalise to a plain assoc array.
			$existingParams = $this->normaliseParams($existing->params ?? null);
			$merged = $existingParams;

			// 1. params_set (lowest precedence among new values)
			if (isset($arguments['params_set']) && is_array($arguments['params_set'])) {
				foreach ($arguments['params_set'] as $k => $v) {
					$key = (string) $k;
					$merged[$key] = $v;
					$paramsTouchedKeys[$key] = true;
				}
			}

			// 2. Named args (override params_set on collision)
			foreach (self::NAMED_PARAMS as $publicArg => $jKey) {
				if (array_key_exists($publicArg, $arguments)) {
					$merged[$jKey] = $arguments[$publicArg];
					$paramsTouchedKeys[$jKey] = true;
				}
			}

			// 3. params_unset (highest precedence — explicit deletion)
			if (isset($arguments['params_unset']) && is_array($arguments['params_unset'])) {
				foreach ($arguments['params_unset'] as $k) {
					$key = (string) $k;
					unset($merged[$key]);
					$paramsTouchedKeys[$key] = true;
				}
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
		if (!$model->save($data)) {
			return ToolResult::error('com_menus rejected the update: ' . $model->getError());
		}

		$response = ['ok' => true, 'id' => $id];
		if ($paramsTouched) {
			$response['params_modified'] = array_keys($paramsTouchedKeys);
		}
		return ToolResult::json($response);
	}

	/**
	 * Resolve `option=` in a menu link to its #__extensions.extension_id.
	 *
	 * Returns 0 when the link carries no option, or names a component that is
	 * not installed — in which case the caller leaves component_id alone rather
	 * than writing a 0 that would orphan the item from its component.
	 */
	private function componentIdForLink(string $link): int
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

	/**
	 * Coerce com_menus' polymorphic params return into a plain assoc array.
	 */
	private function normaliseParams(mixed $params): array
	{
		if ($params instanceof Registry) {
			return $params->toArray();
		}
		if (is_array($params)) {
			return $params;
		}
		if (is_object($params)) {
			return json_decode(json_encode($params), true) ?? [];
		}
		if (is_string($params) && $params !== '') {
			$decoded = json_decode($params, true);
			return is_array($decoded) ? $decoded : [];
		}
		return [];
	}
}
