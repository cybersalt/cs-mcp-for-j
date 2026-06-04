<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class CreateCustomFieldTool extends AbstractTool
{
	public function getName(): string { return 'create_custom_field'; }

	public function getDescription(): string
	{
		return 'Create a custom field. Required: title, name (lowercase machine name), type '
			. '(text, textarea, editor, list, media, calendar, integer, checkboxes, etc.), '
			. 'context (e.g. "com_content.article", "com_users.user"). Optional: label, '
			. 'description, required, default_value, fieldparams.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'name', 'type', 'context'],
			'properties' => [
				'title'         => ['type' => 'string'],
				'name'          => ['type' => 'string', 'description' => 'Lowercase machine name (no spaces).'],
				'label'         => ['type' => 'string'],
				'type'          => ['type' => 'string'],
				'context'       => ['type' => 'string'],
				'description'   => ['type' => 'string'],
				'required'      => ['type' => 'integer', 'enum' => [0, 1]],
				'default_value' => ['type' => 'string'],
				'state'         => ['type' => 'integer', 'enum' => [0, 1]],
				'group_id'      => ['type' => 'integer'],
				'access'        => ['type' => 'integer'],
				'language'      => ['type' => 'string'],
				'fieldparams'   => ['type' => 'object'],
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
			'required'      => isset($arguments['required']) ? (int) $arguments['required'] : 0,
			'default_value' => (string) ($arguments['default_value'] ?? ''),
			'state'         => isset($arguments['state']) ? (int) $arguments['state'] : 1,
			'group_id'      => isset($arguments['group_id']) ? (int) $arguments['group_id'] : 0,
			'access'        => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'language'      => (string) ($arguments['language'] ?? '*'),
			'fieldparams'   => json_encode((object) ($arguments['fieldparams'] ?? [])),
			'params'        => json_encode(new \stdClass()),
		];

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
