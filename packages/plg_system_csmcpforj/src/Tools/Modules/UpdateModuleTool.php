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

/**
 * @partial-save-safe com_modules' ModuleModel::save() goes through
 *   AdminModel::save(), which loads the row and binds only the keys supplied,
 *   and Table\Module::bind() reads only params/rules when present. The one key
 *   it reads unconditionally is the menu assignment (`$data['assignment'] ?? 0`
 *   — absent means "all pages", after it has deleted the #__modules_menu rows),
 *   so the current assignment is always re-supplied from getItem(). Associations
 *   are only touched when non-empty, so omitting them is safe. params are
 *   merged onto the stored blob via mergeJsonPatch(). Reviewed against Joomla
 *   6.2.0, 2026-10-20.
 */
final class UpdateModuleTool extends AbstractTool
{
	use ModuleFieldsTrait;

	private const UPDATABLE_SCALARS = ['title', 'note', 'content', 'position', 'ordering', 'access', 'showtitle', 'language', 'published'];

	public function getName(): string { return 'update_module'; }

	public function getDescription(): string
	{
		return 'Update an existing module. Required: id. Only the fields you pass change. Fields: '
			. 'title, note, showtitle, published (1, 0, -2 trash), publish_up / publish_down (UTC '
			. '"YYYY-MM-DD HH:MM:SS", empty string clears), position, ordering, access, language, '
			. 'content (mod_custom HTML), associations ({"fr-FR": module id}, multilingual sites). '
			. 'params is a PATCH merged into the stored params: pass only the keys to change; a '
			. 'null value deletes a key (the "Use Global"/default state). It covers the Advanced tab '
			. '(layout, moduleclass_sfx, module_tag, bootstrap_size, header_tag, header_class, style, '
			. 'cache) and the module type\'s own options. Menu Assignment: assigned (-1 all pages, '
			. '0 no pages, 1 only on assignment[], 2 on all except assignment[]) with assignment '
			. '(menu item ids); omit both to keep the current assignment.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'           => ['type' => 'integer'],
				'title'        => ['type' => 'string'],
				'note'         => ['type' => 'string'],
				'content'      => ['type' => 'string', 'description' => 'mod_custom: the HTML body.'],
				'position'     => ['type' => 'string', 'description' => 'Template position (list_module_positions). Empty string = none.'],
				'ordering'     => ['type' => 'integer', 'description' => 'Order within the position (lower first).'],
				'access'       => ['type' => 'integer', 'description' => 'Viewing access level id (list_access_levels).'],
				'showtitle'    => ['type' => 'integer', 'enum' => [0, 1]],
				'language'     => ['type' => 'string', 'description' => 'Language tag, or "*" for all.'],
				'published'    => ['type' => 'integer', 'enum' => [0, 1, -2], 'description' => '1 published, 0 unpublished, -2 trashed.'],
				'publish_up'   => ['type' => 'string', 'description' => 'Start Publishing, UTC "YYYY-MM-DD HH:MM:SS". Empty string clears it.'],
				'publish_down' => ['type' => 'string', 'description' => 'Finish Publishing, UTC "YYYY-MM-DD HH:MM:SS". Empty string clears it.'],
				'params'       => ['type' => 'object', 'description' => 'PATCH merged into the stored params; null deletes a key. ' . self::moduleParamsHelp()],
				'assigned'     => ['type' => 'integer', 'enum' => [-1, 0, 1, 2], 'description' => 'Menu Assignment: -1 all pages, 0 no pages, 1 only on assignment[], 2 on all except assignment[]. Omit to keep the current assignment.'],
				'assignment'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Menu item ids, used when assigned is 1 or 2.'],
				'associations' => ['type' => 'object', 'description' => 'Multilingual sites, site modules: language tag => id of the module that translates this one. Needs a specific language (not "*").'],
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

		foreach (['publish_up', 'publish_down'] as $dateKey) {
			$date = $this->normaliseModuleDate($arguments, $dateKey);
			if ($date !== null) {
				$data[$dateKey] = $date;
			}
		}

		if (isset($arguments['params']) && is_array($arguments['params'])) {
			// getItem() hands back params as an array already; mergeJsonPatch
			// copes with every other shape too. A null value deletes the key.
			$data['params'] = $this->mergeJsonPatch($existing->params ?? [], $arguments['params']);
		}

		// When the caller does not touch the assignment at all, the existing one
		// has to be carried through the save, otherwise the model's default
		// (assignment = 0) silently resets the module to all pages.
		if (isset($arguments['assigned'])) {
			$data = array_merge($data, $this->moduleAssignmentPayload(
				(int) $arguments['assigned'],
				isset($arguments['assignment']) && is_array($arguments['assignment']) ? $arguments['assignment'] : []
			));
		} else {
			$data['assignment'] = $existing->assignment ?? 0;
			$data['assigned']   = array_map(static fn ($v) => abs((int) $v), (array) ($existing->assigned ?? []));
		}

		if (array_key_exists('associations', $arguments)) {
			if ((int) $existing->client_id !== 0 || !Associations::isEnabled()) {
				return ToolResult::error('associations can only be set on a site module while language associations are enabled.');
			}

			$language = (string) ($data['language'] ?? $existing->language);
			$associations = $this->normaliseModuleAssociations($arguments['associations']);

			if ($associations !== [] && $language === '*') {
				return ToolResult::error('A module set to "All" languages cannot have associations. Set language to a specific tag first (it can be done in the same call).');
			}

			$data['associations'] = $associations;
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_modules rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
