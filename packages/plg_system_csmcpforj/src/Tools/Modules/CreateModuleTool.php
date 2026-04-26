<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class CreateModuleTool extends AbstractTool
{
	public function getName(): string { return 'create_module'; }

	public function getDescription(): string
	{
		return 'Create a new module instance. Required: title, module (e.g. "mod_custom"), '
			. 'position. Optional: content (HTML for mod_custom), params (object), client_id '
			. '(0=site default, 1=admin), language, access, published, showtitle. Default '
			. 'menu assignment is "all pages".';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'module', 'position'],
			'properties' => [
				'title'     => ['type' => 'string'],
				'module'    => ['type' => 'string', 'description' => 'e.g. "mod_custom", "mod_menu", "mod_breadcrumbs".'],
				'position'  => ['type' => 'string'],
				'content'   => ['type' => 'string', 'description' => 'For mod_custom: HTML body.'],
				'params'    => ['type' => 'object'],
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
				'note'      => ['type' => 'string'],
				'showtitle' => ['type' => 'integer', 'enum' => [0, 1]],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'language'  => ['type' => 'string'],
				'access'    => ['type' => 'integer'],
				'assigned'  => ['type' => 'integer', 'enum' => [-1, 0, 1, 2], 'description' => '-1=on all, 0=on none, 1=only on assignment[], 2=on all except assignment[].'],
				'assignment' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids when assigned in (1, 2).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$data = [
			'id'        => 0,
			'title'     => $this->requireString($arguments, 'title'),
			'note'      => (string) ($arguments['note'] ?? ''),
			'content'   => (string) ($arguments['content'] ?? ''),
			'position'  => $this->requireString($arguments, 'position'),
			'module'    => $this->requireString($arguments, 'module'),
			'access'    => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'showtitle' => isset($arguments['showtitle']) ? (int) $arguments['showtitle'] : 1,
			'params'    => json_encode((object) ($arguments['params'] ?? [])),
			'client_id' => isset($arguments['client_id']) ? (int) $arguments['client_id'] : 0,
			'language'  => (string) ($arguments['language'] ?? '*'),
			'published' => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'assigned'  => isset($arguments['assigned']) ? (int) $arguments['assigned'] : -1, // -1 = all pages
			'assignment' => isset($arguments['assignment']) && is_array($arguments['assignment'])
				? array_map('intval', $arguments['assignment'])
				: [],
		];

		$model = $this->getModel('com_modules', 'Module');
		if (!$model->save($data)) {
			return ToolResult::error('com_modules rejected the module: ' . $model->getError());
		}

		$id = (int) $model->getState($model->getName() . '.id');
		return ToolResult::json([
			'ok'       => true,
			'id'       => $id,
			'title'    => $data['title'],
			'module'   => $data['module'],
			'position' => $data['position'],
			'edit_url' => 'index.php?option=com_modules&task=module.edit&id=' . $id,
		]);
	}
}
