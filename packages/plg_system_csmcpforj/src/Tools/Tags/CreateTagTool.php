<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

final class CreateTagTool extends AbstractTool
{
	public function getName(): string { return 'create_tag'; }

	public function getDescription(): string
	{
		return 'Create a new tag. Required: title. parent_id defaults to 1 (root).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title'],
			'properties' => [
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
		$title = $this->requireString($arguments, 'title');
		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		$data = [
			'id'          => 0,
			'parent_id'   => isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1,
			'title'       => $title,
			'alias'       => $alias,
			'description' => (string) ($arguments['description'] ?? ''),
			'published'   => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'params'      => json_encode(new \stdClass()),
			'metadata'    => json_encode(new \stdClass()),
			'images'      => json_encode(new \stdClass()),
			'urls'        => json_encode(new \stdClass()),
			'created_user_id' => (int) $actor->id,
		];

		$model  = $this->getModel('com_tags', 'Tag');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('com_tags rejected the tag: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $title, 'alias' => $alias];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
