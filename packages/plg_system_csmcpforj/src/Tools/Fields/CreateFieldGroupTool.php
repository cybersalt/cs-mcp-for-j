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
 * Create a custom-field group through com_fields' GroupModel.
 *
 * Covers every field on Joomla 6.2's field-group edit screen (com_fields/
 * forms/group.xml) except the Permissions tab (`rules`), left out on purpose:
 * it writes the group's #__assets row, a privilege change that deserves its
 * own tool. Checked against 6.2.0, 2026-10-20.
 */
final class CreateFieldGroupTool extends AbstractTool
{
	use FieldsArgumentsTrait;
	use \Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions\AuthorChangeTrait;

	public function getName(): string { return 'create_field_group'; }

	public function getDescription(): string
	{
		return 'Create a custom-field group. Required: title, context (e.g. '
			. '"com_content.article", "com_users.user"). Optional: state (1 published (default), '
			. '0 unpublished, 2 archived, -2 trashed), access (default 1 Public), language (default '
			. '"*"), description, note, ordering, created_by, created (UTC), params '
			. '({display_readonly: "1" show the group\'s fields read-only to users who cannot edit '
			. 'them (default), "0" hide them}). The group '
			. 'becomes a tab in the article editor — assign custom fields to it via '
			. 'update_custom_field(group_id=...). Goes through com_fields\' GroupModel so all '
			. 'standard save hooks fire.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['title', 'context'],
			'properties' => [
				'title'       => ['type' => 'string'],
				'context'     => ['type' => 'string'],
				'state'       => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published (default), 0 unpublished, 2 archived, -2 trashed.'],
				'access'      => ['type' => 'integer', 'description' => 'Viewing access level id (list_access_levels). Default 1 (Public).'],
				'language'    => ['type' => 'string', 'description' => 'Language code, or "*" for all (default).'],
				'description' => ['type' => 'string'],
				'note'        => ['type' => 'string'],
				'ordering'    => ['type' => 'integer'],
				'created_by'  => ['type' => 'integer', 'description' => 'Created by: user id. Defaults to you.'],
				'created'     => ['type' => 'string', 'description' => 'Created date in UTC, e.g. "2026-10-20 14:30:00". Defaults to now.'],
				'params'      => ['type' => 'object', 'description' => 'Options. Key: display_readonly ("1" show the fields read-only to users who cannot edit them, the default; "0" hide them).', 'additionalProperties' => true],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$context = $this->requireString($arguments, 'context');
		$data = [
			'id'          => 0,
			'title'       => $this->requireString($arguments, 'title'),
			'context'     => $context,
			'state'       => isset($arguments['state']) ? $this->fieldState($arguments['state']) : 1,
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'description' => (string) ($arguments['description'] ?? ''),
			'note'        => (string) ($arguments['note'] ?? ''),
			'params'      => json_encode((object) (isset($arguments['params'])
				? array_filter($this->fieldObjectArg($arguments['params'], 'params'), static fn ($v) => $v !== null)
				: [])),
		];
		if (isset($arguments['ordering'])) {
			$data['ordering'] = (int) $arguments['ordering'];
		}
		if (isset($arguments['created_by'])) {
			$data['created_by'] = $this->fieldCreatorId($arguments['created_by'], 'created_by');
			$this->requireCanSetAuthor($actor, (int) $data['created_by'], 'created_by', true);
		}
		if (isset($arguments['created'])) {
			$data['created'] = $this->fieldCreatedDate($arguments['created'], 'created');
		}

		$model = $this->getModel('com_fields', 'Group');
		// GroupModel keys state by its own name (e.g. "group.context"). Set the context state
		// before save so the model resolves the correct asset rules for the new group.
		$model->setState($model->getName() . '.context', $context);

		$result = $this->saveAdminModel($model, $data);
		if ($result['id'] <= 0) {
			return ToolResult::error('com_fields rejected the field group: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $data['title'], 'context' => $context];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
