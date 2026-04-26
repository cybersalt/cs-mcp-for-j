<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Menus;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class DeleteMenuItemTool extends AbstractTool
{
	public function getName(): string { return 'delete_menu_item'; }

	public function getDescription(): string { return 'Trash or permanently delete menu item(s). Default: trash.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer'],
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'permanent' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ids = [];
		if (isset($arguments['id'])) { $ids[] = (int) $arguments['id']; }
		if (isset($arguments['ids']) && is_array($arguments['ids'])) {
			foreach ($arguments['ids'] as $i) { $ids[] = (int) $i; }
		}
		$ids = array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
		if ($ids === []) {
			return ToolResult::error('Provide id or ids[].');
		}

		$model = $this->getModel('com_menus', 'Item');

		if (!empty($arguments['permanent'])) {
			$idsCopy = $ids;
			if (!$model->delete($idsCopy)) {
				return ToolResult::error('Permanent delete rejected: ' . $model->getError());
			}
			return ToolResult::json(['ok' => true, 'deleted' => $ids, 'permanent' => true]);
		}

		$idsCopy = $ids;
		if (!$model->publish($idsCopy, -2)) {
			return ToolResult::error('Trash rejected: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'trashed' => $ids, 'permanent' => false]);
	}
}
