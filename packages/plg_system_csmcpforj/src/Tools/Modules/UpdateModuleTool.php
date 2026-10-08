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

		// Issue #17: ModuleModel::save() expects $data['assignment'] = MODE
		// (0 = all, 1 = only, -1 = all except, non-numeric = none) and
		// $data['assigned'] = ARRAY of menu item ids; the tool schema uses the
		// opposite names. And when the caller does not touch the assignment at
		// all, the existing one has to be carried through the save, otherwise the
		// model's default (assignment = 0) silently resets the module to all pages.
		if (isset($arguments['assigned'])) {
			$mode = (int) $arguments['assigned']; // tool: -1 all, 0 none, 1 only, 2 except
			$ids  = isset($arguments['assignment']) && is_array($arguments['assignment'])
				? array_map('intval', $arguments['assignment'])
				: [];
			if (in_array($mode, [1, 2], true) && $ids === []) {
				return ToolResult::error('assignment[] (menu item ids) is required when assigned is 1 or 2.');
			}
			switch ($mode) {
				case 0:  $data['assignment'] = '-'; $data['assigned'] = [];   break;
				case 1:  $data['assignment'] = 1;   $data['assigned'] = $ids; break;
				case 2:  $data['assignment'] = -1;  $data['assigned'] = $ids; break;
				default: $data['assignment'] = 0;   $data['assigned'] = [];   break;
			}
		} else {
			$data['assignment'] = $existing->assignment ?? 0;
			$data['assigned']   = array_map(static fn ($v) => abs((int) $v), (array) ($existing->assigned ?? []));
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_modules rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
