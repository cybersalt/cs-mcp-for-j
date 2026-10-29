<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\User\User;

final class CreateModuleTool extends AbstractTool
{
	use ModuleFieldsTrait;

	public function getName(): string { return 'create_module'; }

	public function getDescription(): string
	{
		return 'Create a new module instance. Required: title, module (e.g. "mod_custom"; see '
			. 'list_module_types), position (list_module_positions). Optional: content (HTML for '
			. 'mod_custom), note, showtitle, published, publish_up / publish_down (UTC "YYYY-MM-DD '
			. 'HH:MM:SS"), ordering, access, language, client_id (0 site default, 1 admin), params '
			. '(object: the Advanced tab — layout, moduleclass_sfx, module_tag, bootstrap_size, '
			. 'header_tag, header_class, style, cache — and the module type\'s own options), '
			. 'assigned/assignment[] (Menu Assignment; default all pages), associations '
			. '({"fr-FR": module id}, multilingual sites).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'module', 'position'],
			'properties' => [
				'title'        => ['type' => 'string'],
				'module'       => ['type' => 'string', 'description' => 'e.g. "mod_custom", "mod_menu", "mod_breadcrumbs".'],
				'position'     => ['type' => 'string'],
				'content'      => ['type' => 'string', 'description' => 'For mod_custom: HTML body.'],
				'params'       => ['type' => 'object', 'description' => self::moduleParamsHelp()],
				'client_id'    => ['type' => 'integer', 'enum' => [0, 1]],
				'note'         => ['type' => 'string'],
				'showtitle'    => ['type' => 'integer', 'enum' => [0, 1]],
				'published'    => ['type' => 'integer', 'enum' => [0, 1]],
				'publish_up'   => ['type' => 'string', 'description' => 'Start Publishing, UTC "YYYY-MM-DD HH:MM:SS".'],
				'publish_down' => ['type' => 'string', 'description' => 'Finish Publishing, UTC "YYYY-MM-DD HH:MM:SS".'],
				'ordering'     => ['type' => 'integer', 'description' => 'Order within the position. Default: last.'],
				'language'     => ['type' => 'string'],
				'access'       => ['type' => 'integer'],
				'assigned'     => ['type' => 'integer', 'enum' => [-1, 0, 1, 2], 'description' => '-1=on all, 0=on none, 1=only on assignment[], 2=on all except assignment[].'],
				'assignment'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids when assigned in (1, 2).'],
				'associations' => ['type' => 'object', 'description' => 'Multilingual sites, site modules: language tag => id of the module that translates this one. Needs a specific language (not "*").'],
			] + self::moduleAdvancedSchema(),
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
			'params'    => json_encode((object) $this->applyModuleAdvanced($this->mergeJsonPatch([], (array) ($arguments['params'] ?? [])), $arguments)),
			'client_id' => isset($arguments['client_id']) ? (int) $arguments['client_id'] : 0,
			'language'  => (string) ($arguments['language'] ?? '*'),
			'published' => isset($arguments['published']) ? (int) $arguments['published'] : 1,
		];

		if (isset($arguments['ordering'])) {
			$data['ordering'] = (int) $arguments['ordering'];
		}

		foreach (['publish_up', 'publish_down'] as $dateKey) {
			$date = $this->normaliseModuleDate($arguments, $dateKey);
			if ($date !== null && $date !== '') {
				$data[$dateKey] = $date;
			}
		}

		$data = array_merge($data, $this->moduleAssignmentPayload(
			isset($arguments['assigned']) ? (int) $arguments['assigned'] : -1,
			isset($arguments['assignment']) && is_array($arguments['assignment']) ? $arguments['assignment'] : []
		));

		if (array_key_exists('associations', $arguments)) {
			if ($data['client_id'] !== 0 || !Associations::isEnabled()) {
				return ToolResult::error('associations can only be set on a site module while language associations are enabled.');
			}

			$associations = $this->normaliseModuleAssociations($arguments['associations']);

			if ($associations !== [] && $data['language'] === '*') {
				return ToolResult::error('A module set to "All" languages cannot have associations. Pass a specific language.');
			}

			$data['associations'] = $associations;
		}

		$model  = $this->getModel('com_modules', 'Module');
		$result = $this->saveAdminModel($model, $data);

		if ($result['id'] <= 0) {
			return ToolResult::error('com_modules rejected the module: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = [
			'ok'       => true,
			'id'       => $result['id'],
			'title'    => $data['title'],
			'module'   => $data['module'],
			'position' => $data['position'],
			'edit_url' => 'index.php?option=com_modules&task=module.edit&id=' . $result['id'],
		];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
