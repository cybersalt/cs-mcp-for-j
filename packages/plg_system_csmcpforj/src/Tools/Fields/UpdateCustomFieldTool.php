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
 * Update a custom field via com_fields' FieldModel. PATCH semantics — only
 * the supplied properties are changed, everything else preserved.
 *
 * Notably exposes the three fields the original create_custom_field tool
 * didn't surface for editing post-creation:
 *
 *   - group_id          — moves the field under a different field group
 *                         (use 0 to unassign / show under the generic
 *                         "Fields" tab in the editor).
 *   - assigned_cat_ids  — restricts the field to only show on articles in
 *                         the given categories. Pass an array of category
 *                         ids. Pass [-1] to mean "no categories" (NOT the
 *                         same as omitting — Joomla's UI uses [-1] as the
 *                         explicit "none" sentinel). Pass an empty array
 *                         to mean "all categories" (the default for a new
 *                         field without any category assignment).
 *   - only_use_in_subform — when set to 1, the field is hidden from the
 *                         standard article editor and only appears as a
 *                         child option inside a Subform field's
 *                         fieldparams.options. Required when building
 *                         Subform groups via the API.
 *
 * Context is intentionally NOT updatable — changing a field's context
 * orphans its existing values; create a new field in the new context
 * instead. Type is not updatable either: Joomla 6.2's edit screen makes it
 * read-only once the field exists (FieldModel::preprocessForm()), because the
 * stored values and fieldparams belong to the old type.
 *
 * Field parity with Joomla 6.2.0's edit screen (com_fields/forms/field.xml),
 * reviewed 2026-10-20: every field is reachable here except type and context
 * (above), the read-only modified_* / checked_out* columns, and the
 * Permissions tab (`rules`). Rules are left out on purpose: they rewrite the
 * field's #__assets row, a privilege change that should not ride along on a
 * routine field edit (and save() would REPLACE the rules, not merge them).
 * `name`, `created_user_id` and `created_time` were added that day; each is
 * bound only when supplied.
 *
 * @partial-save-safe FieldModel::save() binds only supplied keys for the
 * field's own columns. Verified behaviourally on Joomla 6.1.4, 2026-10-09:
 * created a field with every field populated, changed only `title`, diffed
 * all 26 fields — nothing else moved.
 *
 * ⚠️ That check could not see #__fields_categories, which save() rewrites
 * from $data['assigned_cat_ids'] every time and treats as "all categories"
 * when absent. The tool now re-supplies the current assignment (2.9.0, found
 * by Susan on cybersalt.org). Lesson: a partial-save check must diff the
 * related tables a model writes, not just the row.
 *
 * ⚠️ Nor could it see inside the params / fieldparams blobs: a `params`
 * argument replaced the whole blob rather than patching it. Both are now
 * merged key by key (2.9.0, reviewed 2026-10-19 against Joomla 6.2.0's
 * FieldModel::save(), which reads nothing else off $data before bind outside
 * the save2copy branch).
 */
final class UpdateCustomFieldTool extends AbstractTool
{
	use FieldsArgumentsTrait;

	private const UPDATABLE_SCALARS = [
		'title', 'name', 'label', 'description', 'required', 'group_id',
		'access', 'language', 'default_value', 'note', 'ordering',
		'only_use_in_subform',
	];

	public function getName(): string { return 'update_custom_field'; }

	public function getDescription(): string
	{
		return 'Update a custom field. Required: id. Any of: title, name (the unique machine name; '
			. 'layout overrides that look a field up by name must be updated to match), label, '
			. 'description, required, state (1 published, 0 unpublished, 2 archived, -2 trashed), '
			. 'group_id (assign to a field group tab — see list_field_groups; 0 = '
			. 'unassigned), access, language, default_value, ordering, note, created_user_id, '
			. 'created_time (UTC), only_use_in_subform '
			. '(1 hides the field from the standard editor — required when building Subform '
			. 'children via the API), assigned_cat_ids (array of category ids — pass [-1] for '
			. '"no categories", [] for "all categories"; omit to keep the current assignment), '
			. 'fieldparams (object — type-specific config like the Subform options array), params '
			. '(object — generic display options). fieldparams and params are MERGED into the '
			. 'stored values key by key: pass only the keys to change, null deletes a key, and a '
			. 'nested value such as the options array is replaced whole. PATCH semantics: '
			. 'untouched properties preserve their current value. Context and type are '
			. 'intentionally NOT updatable (Joomla does not allow a type change either) — create a '
			. 'new field instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'                  => ['type' => 'integer'],
				'title'               => ['type' => 'string'],
				'name'                => ['type' => 'string', 'description' => 'Unique machine name. Joomla makes it URL-safe and refuses a name another field already has.'],
				'label'               => ['type' => 'string'],
				'description'         => ['type' => 'string'],
				'required'            => ['type' => 'integer', 'enum' => [0, 1]],
				'state'               => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published, 0 unpublished, 2 archived, -2 trashed.'],
				'group_id'            => ['type' => 'integer'],
				'access'              => ['type' => 'integer'],
				'language'            => ['type' => 'string'],
				'default_value'       => ['type' => 'string'],
				'note'                => ['type' => 'string'],
				'ordering'            => ['type' => 'integer'],
				'only_use_in_subform' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = only usable inside a Subform field; Joomla then forces its categories to none.'],
				'created_user_id'     => ['type' => 'integer', 'description' => 'Created by: user id (list_users).'],
				'created_time'        => ['type' => 'string', 'description' => 'Created date in UTC, e.g. "2026-10-20 14:30:00".'],
				'assigned_cat_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids the field shows on. [-1] = no categories. [] = all categories.'],
				'fieldparams'         => ['type' => 'object', 'description' => 'Merged into the stored fieldparams key by key; null deletes a key.'],
				'params'              => $this->customFieldParamsSchema('The Options tab. Merged into the stored params key by key; null deletes a key.'),
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$model = $this->getModel('com_fields', 'Field');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Custom field ' . $id . ' not found.');
		}

		// PATCH baseline — every column the model expects on save(), seeded
		// from existing values so omitted args preserve current state.
		$data = [
			'id'                  => $id,
			'title'               => $existing->title,
			'name'                => $existing->name,
			'label'               => $existing->label,
			'type'                => $existing->type,
			'context'             => $existing->context,
			'description'         => $existing->description,
			'required'            => $existing->required,
			'state'               => $existing->state,
			'group_id'            => $existing->group_id,
			'access'              => $existing->access,
			'language'            => $existing->language,
			'default_value'       => $existing->default_value,
			'note'                => $existing->note ?? '',
			'ordering'            => $existing->ordering ?? 0,
			'only_use_in_subform' => $existing->only_use_in_subform ?? 0,
			'fieldparams'         => is_string($existing->fieldparams) ? $existing->fieldparams : json_encode($existing->fieldparams ?: new \stdClass()),
			'params'              => is_string($existing->params) ? $existing->params : json_encode($existing->params ?: new \stdClass()),
		];

		foreach (self::UPDATABLE_SCALARS as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (array_key_exists('state', $arguments)) {
			$data['state'] = $this->fieldState($arguments['state']);
		}
		// Publishing-tab fields: bound only when supplied, so an update that
		// leaves them out never touches them (FieldTable::check() only fills
		// created_time when it is empty and never touches created_user_id on
		// an existing row).
		if (array_key_exists('created_user_id', $arguments)) {
			$data['created_user_id'] = $this->fieldCreatorId($arguments['created_user_id'], 'created_user_id');
		}
		if (array_key_exists('created_time', $arguments)) {
			$data['created_time'] = $this->fieldCreatedDate($arguments['created_time'], 'created_time');
		}

		/*
		 * params / fieldparams are MERGED onto the stored blob, key by key, as
		 * the description promises ("untouched properties preserve their
		 * current value"). They used to be replaced outright, so a call that
		 * set only params.hint dropped every other key in the blob (display
		 * position, label/render classes, layout, showon...) and the field fell
		 * back to Joomla's defaults for all of them (spotted 2026-10-19 while
		 * looking at a params-only update on newsite.westcoastwaterproofingltd
		 * .com). A null value deletes a key.
		 * The merge is one level deep: a nested value such as the Subform /
		 * list `options` array is replaced as a whole, which is what a caller
		 * editing that list means.
		 */
		if (array_key_exists('fieldparams', $arguments)) {
			$data['fieldparams'] = json_encode((object) $this->mergeJsonPatch($existing->fieldparams ?? null, $this->fieldObjectArg($arguments['fieldparams'], 'fieldparams')));
		}
		if (array_key_exists('params', $arguments)) {
			$data['params'] = json_encode((object) $this->mergeJsonPatch($existing->params ?? null, $this->fieldObjectArg($arguments['params'], 'params')));
		}
		if (array_key_exists('assigned_cat_ids', $arguments)) {
			// Pass through as an array — com_fields' FieldModel persists the
			// M:N relationship to #__fields_categories via the standard
			// "assigned_cat_ids" form-data convention.
			$data['assigned_cat_ids'] = array_values(array_map('intval', (array) $arguments['assigned_cat_ids']));
		} else {
			// Re-supply the current assignment. FieldModel::save() rewrites
			// #__fields_categories from $data['assigned_cat_ids'] on EVERY save
			// and treats a missing key as "all categories", so a call that only
			// published a field ({id, state: 1}) un-restricted it and it showed
			// on every article on the site. Found by Susan on cybersalt.org,
			// 2026-10-15, the same pattern as the tag bug. Stored as-is: no rows
			// means all categories, a -1 row means none, so copying the rows
			// back preserves both.
			$data['assigned_cat_ids'] = array_map('intval', (array) $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('category_id'))
					->from($this->db->quoteName('#__fields_categories'))
					->where($this->db->quoteName('field_id') . ' = ' . (int) $id)
			)->loadColumn());
		}

		$model->setState($model->getName() . '.context', $existing->context);

		if (!$model->save($data)) {
			return ToolResult::error('com_fields rejected the field update: ' . $model->getError());
		}

		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
