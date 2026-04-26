<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateArticleTool extends AbstractTool
{
	private const UPDATABLE_SCALARS = [
		'title', 'alias', 'catid', 'state', 'language', 'access',
		'metadesc', 'metakey', 'featured',
	];

	public function getName(): string { return 'update_article'; }

	public function getDescription(): string
	{
		return 'Update an existing article. Required: id. Only the fields you supply are changed; '
			. 'all others are preserved. Use articletext to replace the body, or set state to '
			. 'publish (1), unpublish (0), archive (2), or trash (-2).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'Article id to update.'],
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'catid'       => ['type' => 'integer'],
				'articletext' => ['type' => 'string', 'description' => 'Replacement HTML body.'],
				'state'       => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'featured'    => ['type' => 'integer', 'enum' => [0, 1]],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_content', 'Article');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE_SCALARS as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (array_key_exists('articletext', $arguments)) {
			$data['articletext'] = (string) $arguments['articletext'];
		}
		$data['modified_by'] = (int) $actor->id;

		if (!$model->save($data)) {
			return ToolResult::error('com_content rejected the update: ' . $model->getError());
		}

		return ToolResult::json([
			'ok'             => true,
			'id'             => $id,
			'fields_changed' => array_values(array_diff(array_keys($data), ['id', 'modified_by'])),
			'edit_url'       => 'index.php?option=com_content&task=article.edit&id=' . $id,
		]);
	}
}
