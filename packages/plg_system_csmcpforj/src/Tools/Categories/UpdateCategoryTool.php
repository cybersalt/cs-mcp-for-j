<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateCategoryTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'alias', 'parent_id', 'description', 'metadesc', 'metakey', 'published', 'language', 'access'];

	public function getName(): string { return 'update_category'; }

	public function getDescription(): string
	{
		return 'Update an existing category. Required: id. Only fields you supply are changed.';
	}

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
				'description' => ['type' => 'string', 'description' => 'On-page category description (HTML).'],
				'metadesc'    => ['type' => 'string', 'description' => 'Meta description tag for SEO (plain text, no HTML).'],
				'metakey'     => ['type' => 'string', 'description' => 'Comma-separated meta keywords.'],
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
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_categories', 'Category');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Category ' . $id . ' not found.');
		}

		$model->setState($model->getName() . '.extension', $existing->extension);

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		$data['modified_user_id'] = (int) $actor->id;

		if (!$model->save($data)) {
			return ToolResult::error('com_categories rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id, 'fields_changed' => array_keys(array_diff_key($data, ['id' => 1, 'modified_user_id' => 1]))]);
	}
}
