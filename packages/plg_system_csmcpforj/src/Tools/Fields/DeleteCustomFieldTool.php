<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class DeleteCustomFieldTool extends AbstractTool
{
	public function getName(): string { return 'delete_custom_field'; }

	public function getDescription(): string
	{
		return 'Delete a custom field. Required: id, confirm (must be true). Calls com_fields\' '
			. 'FieldModel::delete() so every value of the field across every article (or other '
			. 'content item in the field\'s context) is also removed from #__fields_values. '
			. 'Destructive and not reversible — if you just want to hide the field, '
			. 'update_custom_field(state=0) is the non-destructive alternative.';
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
			return ToolResult::error('Refusing to delete custom field ' . $id . ' without confirm=true. Pass confirm:true to proceed.');
		}

		$model = $this->getModel('com_fields', 'Field');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Custom field ' . $id . ' not found.');
		}

		// com_fields' FieldModel::canDelete() refuses unless state=-2 (trashed) —
		// verified in administrator/components/com_fields/src/Model/FieldModel.php ~line 831.
		// Joomla's UI is a two-phase delete: trash first ("State → Trashed"), then
		// "Empty Trash" hard-deletes. Replicate that here so the tool's "delete" verb
		// behaves like the user expects (one call = gone) instead of leaving the field
		// in a half-trashed state.
		if ((int) $existing->state !== -2) {
			$trashIds = [$id];
			if (!$model->publish($trashIds, -2)) {
				return ToolResult::error('com_fields refused to trash the field before hard-delete: ' . ($model->getError() ?: 'unknown error from publish'));
			}
		}

		// FieldModel::delete() takes ids by reference and returns bool.
		$ids = [$id];
		if (!$model->delete($ids)) {
			return ToolResult::error('com_fields rejected the field delete: ' . ($model->getError() ?: 'unknown error (model returned false without setting an error message)'));
		}

		return ToolResult::json(['ok' => true, 'id' => $id, 'deleted' => true]);
	}
}
