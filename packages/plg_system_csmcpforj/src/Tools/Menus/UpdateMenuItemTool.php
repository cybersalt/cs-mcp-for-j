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

	private const VALID_ROBOTS = ['', 'index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'];

	public function getName(): string { return 'update_menu_item'; }

	public function getDescription(): string
	{
		return 'Update a menu item. Required: id. Top-level columns: title, alias, menutype, '
			. 'parent_id, link, published, home, language, access, note, browserNav, '
			. 'template_style_id. PARAMS (the JSON blob templates and SEO plugins read): use '
			. 'named args browser_page_title (the <title> tag override), meta_description, '
			. 'meta_keywords, robots ("", "index,follow", "noindex,follow", "index,nofollow", '
			. '"noindex,nofollow"), page_heading, show_page_heading, page_class_sfx, '
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
