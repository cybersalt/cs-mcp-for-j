<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

/**
 * Create a menu item. The most useful link types in v1:
 *  - URL              link="http://..."   type="url"
 *  - Single article   link="index.php?option=com_content&view=article&id=N"  type="component"
 *  - Category list    link="index.php?option=com_content&view=category&layout=blog&id=N"
 *  - Alias            link="index.php?Itemid=N"  type="alias"
 *  - Heading          (no link)            type="heading"
 *  - Separator        (no link)            type="separator"
 */
final class CreateMenuItemTool extends AbstractTool
{
	public function getName(): string { return 'create_menu_item'; }

	public function getDescription(): string
	{
		return 'Create a menu item. Required: title, menutype (use list_menus), type (component|url|alias|heading|separator). '
			. 'For type=component, supply a link like "index.php?option=com_content&view=article&id=42". '
			. 'For type=url, supply link="https://...". parent_id defaults to 1 (root).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'menutype', 'type'],
			'properties' => [
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'menutype'    => ['type' => 'string'],
				'type'        => ['type' => 'string', 'enum' => ['component', 'url', 'alias', 'heading', 'separator']],
				'link'        => ['type' => 'string'],
				'parent_id'   => ['type' => 'integer'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1]],
				'home'        => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 to make this the home page (per language).'],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
				'note'        => ['type' => 'string'],
				'browserNav'  => ['type' => 'integer', 'enum' => [0, 1, 2], 'description' => '0=parent, 1=new with nav, 2=new without nav.'],
				'template_style_id' => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title    = $this->requireString($arguments, 'title');
		$menutype = $this->requireString($arguments, 'menutype');
		$type     = $this->requireString($arguments, 'type');

		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		// Map type to component / link
		$component_id = 0;
		$link         = (string) ($arguments['link'] ?? '');
		if ($type === 'component' && $link !== '') {
			parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $linkArgs);
			$option = $linkArgs['option'] ?? '';
			if ($option !== '') {
				$query = $this->db->getQuery(true)
					->select($this->db->quoteName('extension_id'))
					->from($this->db->quoteName('#__extensions'))
					->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
					->where($this->db->quoteName('element') . ' = ' . $this->db->quote((string) $option));
				$component_id = (int) $this->db->setQuery($query)->loadResult();
			}
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
			'parent_id'         => isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1,
			'level'             => 1,
			'component_id'      => $component_id,
			'access'            => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'          => (string) ($arguments['language'] ?? '*'),
			'home'              => isset($arguments['home']) ? (int) $arguments['home'] : 0,
			'browserNav'        => isset($arguments['browserNav']) ? (int) $arguments['browserNav'] : 0,
			'template_style_id' => isset($arguments['template_style_id']) ? (int) $arguments['template_style_id'] : 0,
			'params'            => json_encode(new \stdClass()),
			'client_id'         => 0,
		];

		$model  = $this->getModel('com_menus', 'Item');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('com_menus rejected the menu item: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $title, 'alias' => $alias, 'menutype' => $menutype];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
