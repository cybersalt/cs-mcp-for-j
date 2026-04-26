<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateTagTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'alias', 'parent_id', 'description', 'published', 'language', 'access'];

	public function getName(): string { return 'update_tag'; }

	public function getDescription(): string { return 'Update an existing tag. Required: id.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'          => ['type' => 'integer'],
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'parent_id'   => ['type' => 'integer'],
				'description' => ['type' => 'string'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1]],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_tags', 'Tag');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Tag ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		$data['modified_user_id'] = (int) $actor->id;

		if (!$model->save($data)) {
			return ToolResult::error('com_tags rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
