<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class DeleteFieldGroupTool extends AbstractTool
{
	public function getName(): string { return 'delete_field_group'; }

	public function getDescription(): string
	{
		return 'Delete a custom-field group. Required: id, confirm (must be true). Any fields '
			. 'currently assigned to the group will have their group_id reset to 0 (unassigned — '
			. 'they\'ll appear under the generic "Fields" tab in the article editor). The fields '
			. 'themselves are NOT deleted. Use delete_custom_field for that.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id', 'confirm'],
			'properties' => [
				'id'      => ['type' => 'integer'],
				'confirm' => ['type' => 'boolean', 'description' => 'Must be true. Refuses otherwise.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		if (!($arguments['confirm'] ?? false)) {
			return ToolResult::error('Refusing to delete field group ' . $id . ' without confirm=true. Pass confirm:true to proceed.');
		}

		$model = $this->getModel('com_fields', 'Group');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Field group ' . $id . ' not found.');
		}

		// GroupModel::delete() takes ids by reference and returns bool.
		$ids = [$id];
		if (!$model->delete($ids)) {
			return ToolResult::error('com_fields rejected the field group delete: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id, 'deleted' => true]);
	}
}
