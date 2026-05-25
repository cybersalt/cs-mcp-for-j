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

		// FieldModel::delete() takes ids by reference and returns bool.
		$ids = [$id];
		if (!$model->delete($ids)) {
			return ToolResult::error('com_fields rejected the field delete: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id, 'deleted' => true]);
	}
}
