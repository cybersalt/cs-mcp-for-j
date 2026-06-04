<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class CreateFieldGroupTool extends AbstractTool
{
	public function getName(): string { return 'create_field_group'; }

	public function getDescription(): string
	{
		return 'Create a custom-field group. Required: title, context (e.g. '
			. '"com_content.article", "com_users.user"). Optional: state (default 1 published), '
			. 'access (default 1 Public), language (default "*"), description, note. The group '
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
				'state'       => ['type' => 'integer', 'enum' => [0, 1]],
				'access'      => ['type' => 'integer'],
				'language'    => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'note'        => ['type' => 'string'],
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
			'state'       => isset($arguments['state']) ? (int) $arguments['state'] : 1,
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'description' => (string) ($arguments['description'] ?? ''),
			'note'        => (string) ($arguments['note'] ?? ''),
			'params'      => json_encode(new \stdClass()),
		];

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
