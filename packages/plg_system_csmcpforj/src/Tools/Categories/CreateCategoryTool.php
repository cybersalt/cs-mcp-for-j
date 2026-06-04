<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

final class CreateCategoryTool extends AbstractTool
{
	public function getName(): string { return 'create_category'; }

	public function getDescription(): string
	{
		return 'Create a new category. Required: title, extension (e.g. "com_content"). '
			. 'parent_id defaults to 1 (the root). Returns the new category id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'extension'],
			'properties' => [
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'extension'   => ['type' => 'string', 'description' => 'e.g. "com_content".'],
				'parent_id'   => ['type' => 'integer', 'description' => 'Default 1 (root).'],
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
		$title     = $this->requireString($arguments, 'title');
		$extension = $this->requireString($arguments, 'extension');

		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		$data = [
			'id'          => 0,
			'parent_id'   => isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1,
			'extension'   => $extension,
			'title'       => $title,
			'alias'       => $alias,
			'description' => (string) ($arguments['description'] ?? ''),
			'metadesc'    => (string) ($arguments['metadesc'] ?? ''),
			'metakey'     => (string) ($arguments['metakey'] ?? ''),
			'published'   => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'params'      => json_encode(new \stdClass()),
			'metadata'    => json_encode(new \stdClass()),
			'created_user_id' => (int) $actor->id,
		];

		$model = $this->getModel('com_categories', 'Category');
		$model->setState($model->getName() . '.extension', $extension);

		$result = $this->saveAdminModel($model, $data);
		if ($result['id'] <= 0) {
			return ToolResult::error('com_categories rejected the category: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $title, 'alias' => $alias, 'extension' => $extension];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
