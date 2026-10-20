<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * @partial-save-safe GroupModel::save() binds only supplied keys. Verified
 * behaviourally on Joomla 6.1.4, 2026-10-09: created a group, changed only
 * `title`, diffed all 17 fields — nothing else moved. Re-read against Joomla
 * 6.2.0 on 2026-10-20: GroupModel::save() reads nothing off $data outside the
 * save2copy branch, and GroupTable writes no related table, so there is no
 * #__fields_categories-style trap here. The fields in the group keep their
 * group_id whatever this tool changes.
 *
 * Field parity with Joomla 6.2.0's edit screen (com_fields/forms/group.xml),
 * 2026-10-20: `params` (merged key by key; null deletes), `created_by` and
 * `created` were added and are bound only when supplied; state now takes
 * archived (2) and trashed (-2) too. Not exposed: context (below), the
 * read-only modified* / checked_out* columns, and the Permissions tab
 * (`rules`), which writes the group's #__assets row and deserves its own tool.
 */
final class UpdateFieldGroupTool extends AbstractTool
{
	use FieldsArgumentsTrait;

	private const UPDATABLE = ['title', 'access', 'language', 'description', 'note', 'ordering'];

	public function getName(): string { return 'update_field_group'; }

	public function getDescription(): string
	{
		return 'Update a custom-field group. Required: id. Any of: title, state (1 published, '
			. '0 unpublished, 2 archived, -2 trashed), access, language, description, note, ordering, '
			. 'created_by, created (UTC), params (merged into the stored params key by key; null '
			. 'deletes a key; key: display_readonly "1"/"0"). Context is intentionally NOT updatable — '
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
				'state'       => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published, 0 unpublished, 2 archived, -2 trashed.'],
				'access'      => ['type' => 'integer'],
				'language'    => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'note'        => ['type' => 'string'],
				'ordering'    => ['type' => 'integer'],
				'created_by'  => ['type' => 'integer', 'description' => 'Created by: user id (list_users).'],
				'created'     => ['type' => 'string', 'description' => 'Created date in UTC, e.g. "2026-10-20 14:30:00".'],
				'params'      => ['type' => 'object', 'description' => 'Merged into the stored params key by key; null deletes a key. Key: display_readonly ("1" show the fields read-only to users who cannot edit them, "0" hide them).', 'additionalProperties' => true],
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
		if (array_key_exists('state', $arguments)) {
			$data['state'] = $this->fieldState($arguments['state']);
		}
		if (array_key_exists('params', $arguments)) {
			$data['params'] = json_encode((object) $this->mergeJsonPatch($existing->params ?? null, $this->fieldObjectArg($arguments['params'], 'params')));
		}
		// Bound only when supplied: GroupTable::check() fills `created` only
		// when empty and never touches created_by on an existing row.
		if (array_key_exists('created_by', $arguments)) {
			$data['created_by'] = $this->fieldCreatorId($arguments['created_by'], 'created_by');
		}
		if (array_key_exists('created', $arguments)) {
			$data['created'] = $this->fieldCreatedDate($arguments['created'], 'created');
		}

		$model->setState($model->getName() . '.context', $existing->context);

		if (!$model->save($data)) {
			return ToolResult::error('com_fields rejected the field group update: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
