<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class DeleteCategoryTool extends AbstractTool
{
	public function getName(): string { return 'delete_category'; }

	public function getDescription(): string
	{
		return 'Trash or permanently delete a category. By default, the category is moved to the '
			. 'trash. Set permanent=true to remove an already-trashed category. Cannot delete '
			. 'a category that has children or contained items unless those are removed first.';
	}

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

		$model = $this->getModel('com_categories', 'Category');
		$first = $model->getItem($ids[0]);
		if ($first && !empty($first->extension)) {
			$model->setState($model->getName() . '.extension', $first->extension);
		}

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
