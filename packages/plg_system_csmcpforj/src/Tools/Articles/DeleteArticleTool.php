<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\RequiresTrashFirstTrait;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Delete one or more articles. Default behaviour is "trash" (state=-2) since
 * Joomla's Article model treats delete() as a permanent removal that requires
 * the article to already be trashed. Set permanent=true to skip the trash step
 * and remove immediately.
 */
final class DeleteArticleTool extends AbstractTool
{
	use RequiresTrashFirstTrait;

	public function getName(): string { return 'delete_article'; }

	public function getDescription(): string
	{
		return 'Delete or trash an article (or several at once). By default, articles are moved to '
			. 'the trash (state=-2) so they can be restored. Set permanent=true to remove them '
			. 'from the database entirely (only allowed on already-trashed articles).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'properties' => [
				'id'         => ['type' => 'integer', 'description' => 'Single article id.'],
				'ids'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Multiple article ids. Use this OR id.'],
				'permanent'  => ['type' => 'boolean', 'description' => 'If true, permanently delete already-trashed articles. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ids = [];
		if (isset($arguments['id'])) {
			$ids[] = (int) $arguments['id'];
		}
		if (isset($arguments['ids']) && is_array($arguments['ids'])) {
			foreach ($arguments['ids'] as $i) {
				$ids[] = (int) $i;
			}
		}
		$ids = array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
		if ($ids === []) {
			return ToolResult::error('Provide id or ids[].');
		}

		$permanent = (bool) ($arguments['permanent'] ?? false);
		$model     = $this->getModel('com_content', 'Article');

		if ($permanent) {
			// Belt-and-braces: even though com_content's Article model already
			// refuses to delete non-trashed rows, surface the safety contract
			// explicitly with a friendly message so the AI client gets a clear
			// "trash first" signal instead of Joomla's generic delete error.
			$this->assertAlreadyTrashed($ids, '#__content', 'state', 'article');

			$idsCopy = $ids;
			if (!$model->delete($idsCopy)) {
				return ToolResult::error('Permanent delete rejected: ' . $model->getError());
			}
			return ToolResult::json(['ok' => true, 'deleted' => $ids, 'permanent' => true]);
		}

		// Soft delete = move to trash
		$idsCopy = $ids;
		if (!$model->publish($idsCopy, -2)) {
			return ToolResult::error('Trash rejected: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'trashed' => $ids, 'permanent' => false]);
	}
}
