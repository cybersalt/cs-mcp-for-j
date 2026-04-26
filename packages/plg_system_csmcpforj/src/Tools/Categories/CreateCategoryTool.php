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
			'published'   => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'params'      => json_encode(new \stdClass()),
			'metadata'    => json_encode(new \stdClass()),
			'created_user_id' => (int) $actor->id,
		];

		$model = $this->getModel('com_categories', 'Category');
		$model->setState($model->getName() . '.extension', $extension);

		if (!$model->save($data)) {
			return ToolResult::error('com_categories rejected the category: ' . $model->getError());
		}

		$id = (int) $model->getState($model->getName() . '.id');
		return ToolResult::json(['ok' => true, 'id' => $id, 'title' => $title, 'alias' => $alias, 'extension' => $extension]);
	}
}
