<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class UpdateModuleTool extends AbstractTool
{
	private const UPDATABLE_SCALARS = ['title', 'note', 'content', 'position', 'access', 'showtitle', 'language', 'published'];

	public function getName(): string { return 'update_module'; }

	public function getDescription(): string
	{
		return 'Update an existing module. Required: id. Pass params (object) to merge into '
			. 'the module\'s params; pass assigned/assignment[] to change menu visibility.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'         => ['type' => 'integer'],
				'title'      => ['type' => 'string'],
				'note'       => ['type' => 'string'],
				'content'    => ['type' => 'string'],
				'position'   => ['type' => 'string'],
				'access'     => ['type' => 'integer'],
				'showtitle'  => ['type' => 'integer', 'enum' => [0, 1]],
				'language'   => ['type' => 'string'],
				'published'  => ['type' => 'integer', 'enum' => [0, 1]],
				'params'     => ['type' => 'object'],
				'assigned'   => ['type' => 'integer', 'enum' => [-1, 0, 1, 2]],
				'assignment' => ['type' => 'array', 'items' => ['type' => 'integer']],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_modules', 'Module');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Module ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE_SCALARS as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}

		if (isset($arguments['params'])) {
			$existingParams = (array) ($existing->params ?? []);
			if (is_string($existing->params)) {
				$existingParams = json_decode($existing->params, true) ?: [];
			}
			$merged = array_merge($existingParams, (array) $arguments['params']);
			$data['params'] = json_encode((object) $merged);
		}

		if (isset($arguments['assigned'])) {
			$data['assigned'] = (int) $arguments['assigned'];
		}
		if (isset($arguments['assignment']) && is_array($arguments['assignment'])) {
			$data['assignment'] = array_map('intval', $arguments['assignment']);
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_modules rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
