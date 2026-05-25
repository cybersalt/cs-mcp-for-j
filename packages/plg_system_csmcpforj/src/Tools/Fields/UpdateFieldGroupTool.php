<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateFieldGroupTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'state', 'access', 'language', 'description', 'note', 'ordering'];

	public function getName(): string { return 'update_field_group'; }

	public function getDescription(): string
	{
		return 'Update a custom-field group. Required: id. Any of: title, state, access, '
			. 'language, description, note, ordering. Context is intentionally NOT updatable — '
			. 'changing the context of an existing group would orphan every field assigned to it; '
			. 'create a new group in the new context and reassign fields via update_custom_field '
			. 'instead. Untouched fields preserve their current value (PATCH semantics).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'          => ['type' => 'integer'],
				'title'       => ['type' => 'string'],
				'state'       => ['type' => 'integer', 'enum' => [0, 1]],
				'access'      => ['type' => 'integer'],
				'language'    => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'note'        => ['type' => 'string'],
				'ordering'    => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$model = $this->getModel('com_fields', 'Group');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Field group ' . $id . ' not found.');
		}

		// PATCH semantics — start from existing values, overlay supplied fields.
		$data = [
			'id'          => $id,
			'title'       => $existing->title,
			'context'     => $existing->context,
			'state'       => $existing->state,
			'access'      => $existing->access,
			'language'    => $existing->language,
			'description' => $existing->description,
			'note'        => $existing->note,
			'ordering'    => $existing->ordering,
			'params'      => is_string($existing->params) ? $existing->params : json_encode($existing->params ?: new \stdClass()),
		];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}

		$model->setState($model->getName() . '.context', $existing->context);

		if (!$model->save($data)) {
			return ToolResult::error('com_fields rejected the field group update: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
