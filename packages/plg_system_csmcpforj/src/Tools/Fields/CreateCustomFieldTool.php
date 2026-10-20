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
 * Create a custom field through com_fields' FieldModel, so the save hooks and
 * the #__fields_categories assignment run as they do from the edit screen.
 *
 * Covers every field on Joomla 6.2's field edit screen (com_fields/forms/
 * field.xml) except the Permissions tab (`rules`), which is left to the
 * permission tools on purpose. Checked against 6.2.0, 2026-10-20.
 */
final class CreateCustomFieldTool extends AbstractTool
{
	use FieldsArgumentsTrait;

	public function getName(): string { return 'create_custom_field'; }

	public function getDescription(): string
	{
		return 'Create a custom field. Required: title, name (lowercase machine name), type '
			. '(text, textarea, editor, list, media, calendar, integer, checkboxes, subform, etc.), '
			. 'context (e.g. "com_content.article", "com_users.user"). Optional: label, '
			. 'description, note, required, default_value, state (1 published, 0 unpublished, '
			. '2 archived, -2 trashed), group_id, access, language, ordering, only_use_in_subform, '
			. 'created_user_id, created_time (UTC), fieldparams (type-specific options such as a '
			. 'list\'s options), params (the Options tab: placeholder, CSS classes, display '
			. 'position, label, layout, Smart Search...), assigned_cat_ids (array of category ids '
			. 'the field shows on — pass [-1] for "no categories"; omit or [] for "all '
			. 'categories"). The type cannot be changed later.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'name', 'type', 'context'],
			'properties' => [
				'title'               => ['type' => 'string'],
				'name'                => ['type' => 'string', 'description' => 'Lowercase machine name (no spaces). Must be unique across all fields.'],
				'label'               => ['type' => 'string', 'description' => 'Label shown in the form and when displayed. Defaults to the title.'],
				'type'                => ['type' => 'string', 'description' => 'Field type plugin name. Cannot be changed after creation.'],
				'context'             => ['type' => 'string'],
				'description'         => ['type' => 'string'],
				'note'                => ['type' => 'string', 'description' => 'Admin-only note shown in the field list.'],
				'required'            => ['type' => 'integer', 'enum' => [0, 1]],
				'default_value'       => ['type' => 'string'],
				'state'               => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published (default), 0 unpublished, 2 archived, -2 trashed.'],
				'group_id'            => ['type' => 'integer', 'description' => 'Field group id (list_field_groups); 0 = none.'],
				'access'              => ['type' => 'integer', 'description' => 'Viewing access level id (list_access_levels). Default 1 (Public).'],
				'language'            => ['type' => 'string', 'description' => 'Language code, or "*" for all (default).'],
				'ordering'            => ['type' => 'integer'],
				'only_use_in_subform' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = only usable inside a Subform field (its categories are forced to none).'],
				'created_user_id'     => ['type' => 'integer', 'description' => 'Created by: user id. Defaults to you.'],
				'created_time'        => ['type' => 'string', 'description' => 'Created date in UTC, e.g. "2026-10-20 14:30:00". Defaults to now.'],
				'fieldparams'         => ['type' => 'object', 'description' => 'Type-specific options (the type\'s own settings, e.g. a list\'s options).'],
				'params'              => $this->customFieldParamsSchema('The Options tab.'),
				'assigned_cat_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Category ids the field shows on. [-1] = no categories. Omit or [] = all categories.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$data = [
			'id'            => 0,
			'title'         => $this->requireString($arguments, 'title'),
			'name'          => $this->requireString($arguments, 'name'),
			'label'         => (string) ($arguments['label'] ?? $arguments['title']),
			'type'          => $this->requireString($arguments, 'type'),
			'context'       => $this->requireString($arguments, 'context'),
			'description'   => (string) ($arguments['description'] ?? ''),
			'note'          => (string) ($arguments['note'] ?? ''),
			'required'      => isset($arguments['required']) ? (int) $arguments['required'] : 0,
			'default_value' => (string) ($arguments['default_value'] ?? ''),
			'state'         => isset($arguments['state']) ? $this->fieldState($arguments['state']) : 1,
			'group_id'      => isset($arguments['group_id']) ? (int) $arguments['group_id'] : 0,
			'access'        => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'      => (string) ($arguments['language'] ?? '*'),
			'only_use_in_subform' => isset($arguments['only_use_in_subform']) ? (int) $arguments['only_use_in_subform'] : 0,
			'fieldparams'   => json_encode((object) (isset($arguments['fieldparams'])
				? $this->fieldObjectArg($arguments['fieldparams'], 'fieldparams')
				: [])),
			// Until 2026-10-20 params could not be set at create time, so
			// every field started on Joomla's display defaults. Null values
			// are dropped (nothing to delete on a new field).
			'params'        => json_encode((object) (isset($arguments['params'])
				? array_filter($this->fieldObjectArg($arguments['params'], 'params'), static fn ($v) => $v !== null)
				: [])),
			// Before 2.9.0 this argument was silently dropped, so every field
			// created through MCP landed on "all categories".
			'assigned_cat_ids' => array_values(array_map('intval', (array) ($arguments['assigned_cat_ids'] ?? []))),
		];

		if (isset($arguments['ordering'])) {
			$data['ordering'] = (int) $arguments['ordering'];
		}
		if (isset($arguments['created_user_id'])) {
			$data['created_user_id'] = $this->fieldCreatorId($arguments['created_user_id'], 'created_user_id');
		}
		if (isset($arguments['created_time'])) {
			$data['created_time'] = $this->fieldCreatedDate($arguments['created_time'], 'created_time');
		}

		$model = $this->getModel('com_fields', 'Field');
		$model->setState($model->getName() . '.context', $data['context']);

		$result = $this->saveAdminModel($model, $data);
		if ($result['id'] <= 0) {
			return ToolResult::error('com_fields rejected the field: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $data['title'], 'name' => $data['name'], 'context' => $data['context']];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
