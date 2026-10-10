<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
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

			/*
			 * CategoryModel::publish() reads the extension from the REQUEST, not
			 * from model state:
			 *
			 *     $extension = Factory::getApplication()->getInput()->get('extension');
			 *
			 * then hands it to AfterCategoryChangeStateEvent as `context`, which
			 * is typed `string`. The admin UI always has `&extension=com_content`
			 * in its URL so this is invisible there — but nothing puts it in the
			 * input over MCP, so it arrives null and Joomla 6 throws
			 * `ModelEvent::onSetContext(): Argument #1 ($value) must be of type
			 * string, null given`. The trash path was therefore broken for every
			 * category over MCP, while `permanent` worked because delete() does
			 * not fire that event.
			 *
			 * Setting the input is what the admin request does naturally, so this
			 * puts the model in the state core already expects rather than
			 * working around it. Same family as #17 and #18: core reading from
			 * somewhere the MCP path never populates.
			 */
			Factory::getApplication()->getInput()->set('extension', $first->extension);
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
