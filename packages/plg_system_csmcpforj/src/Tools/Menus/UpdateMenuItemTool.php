<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateMenuItemTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'alias', 'menutype', 'parent_id', 'link', 'published', 'language', 'access', 'home', 'note', 'browserNav', 'template_style_id'];

	public function getName(): string { return 'update_menu_item'; }

	public function getDescription(): string { return 'Update a menu item. Required: id.'; }

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

		if (!$model->save($data)) {
			return ToolResult::error('com_menus rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
