<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Rules;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * The Permissions tab of a single article, category, module, custom field or
 * field group (2.10.0, field review Q3, Tim 2026-10-28: "a separate tool.
 * MCP4J should always honor the Joomla ACL permissions").
 *
 * Kept out of the update_* tools on purpose: a permission change is easy to
 * spot in the activity log as its own tool, and easy to switch off.
 *
 * Joomla's rule: the Permissions tab is only shown (and saved) for someone
 * with core.admin on the item's component ("Configure ACL & Options"), e.g.
 * ArticleModel::getForm() removes the rules field otherwise. Same here.
 *
 * Only the actions the component declares for that kind of item in its
 * access.xml are accepted (e.g. core.delete, core.edit, core.edit.state for an
 * article), the same list the tab shows.
 *
 * With no permission given it changes nothing and returns the item's current
 * rules and the actions available.
 */
final class SetItemPermissionTool extends AbstractTool
{
	private const TYPES = ['article', 'category', 'module', 'field', 'field_group'];

	public function getName(): string { return 'set_item_permission'; }

	public function getDescription(): string
	{
		return 'The Permissions tab of one article, category, module, custom field or field group. '
			. 'Required: item_type, id. Add group_id, permission and value to set one cell (1=Allowed, 0=Denied, '
			. 'null=Inherited); leave permission out to just see the current rules and the permissions available. '
			. 'Needs the same Joomla permission as the tab in the admin: "Configure ACL & Options" (core.admin) on '
			. 'the item\'s component. For a whole component\'s permissions use set_component_permission.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['item_type', 'id'],
			'properties' => [
				'item_type'  => ['type' => 'string', 'enum' => self::TYPES],
				'id'         => ['type' => 'integer', 'description' => 'The item\'s id.'],
				'group_id'   => ['type' => 'integer', 'description' => 'User group id (list_user_groups).'],
				'permission' => ['type' => 'string', 'description' => 'e.g. "core.edit", "core.delete", "core.edit.state". Call without it first to see the list for this item.'],
				'value'      => ['type' => 'integer', 'enum' => [1, 0], 'description' => '1 = Allowed, 0 = Denied. Pass "inherit": true instead to clear the setting.'],
				'inherit'    => ['type' => 'boolean', 'description' => 'true clears this cell so the item inherits from its parent (Inherited on the tab).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$type = (string) ($arguments['item_type'] ?? '');
		if (!in_array($type, self::TYPES, true)) {
			return ToolResult::error('item_type must be one of ' . implode(', ', self::TYPES) . '.');
		}

		$id   = $this->requirePositiveInt($arguments, 'id');
		$item = $this->resolveItem($type, $id);

		if ($item === null) {
			return ToolResult::error('No ' . str_replace('_', ' ', $type) . ' with id ' . $id . '.');
		}

		[$component, $assetName, $section] = $item;

		if (!$actor->authorise('core.admin', $component)) {
			return ToolResult::error(
				'Permission denied: changing or viewing the Permissions tab of this ' . str_replace('_', ' ', $type)
				. ' needs "Configure ACL & Options" on ' . $component . ' in Joomla, the same as in the Joomla admin. '
				. 'Your account does not have it.'
			);
		}

		$actions = $this->sectionActions($component, $section);
		$asset   = $this->loadAsset($assetName);

		if ($asset === null) {
			return ToolResult::error('This ' . str_replace('_', ' ', $type) . ' has no permissions record (#__assets "' . $assetName . '"). Save it once in the Joomla admin to create one.');
		}

		$rules = json_decode((string) $asset['rules'], true);
		$rules = is_array($rules) ? $rules : [];

		if (!array_key_exists('permission', $arguments)) {
			return ToolResult::json([
				'item_type'             => $type,
				'id'                    => $id,
				'asset'                 => $assetName,
				'rules'                 => (object) $rules,
				'permissions_available' => $actions,
			]);
		}

		$permission = $this->requireString($arguments, 'permission');
		$groupId    = $this->requirePositiveInt($arguments, 'group_id');
		$inherit    = !empty($arguments['inherit']);

		if (!in_array($permission, $actions, true)) {
			return ToolResult::error(
				'"' . $permission . '" is not a permission on this ' . str_replace('_', ' ', $type) . '\'s Permissions tab. '
				. 'Available: ' . implode(', ', $actions) . '.'
			);
		}

		if (!$inherit && !array_key_exists('value', $arguments)) {
			return ToolResult::error('Pass value (1 = Allowed, 0 = Denied), or inherit: true to clear the setting.');
		}

		$value = $inherit ? null : (int) $arguments['value'];
		if ($value !== null && $value !== 0 && $value !== 1) {
			return ToolResult::error('value must be 1 (Allowed) or 0 (Denied).');
		}

		if (!(bool) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('1')
				->from($this->db->quoteName('#__usergroups'))
				->where($this->db->quoteName('id') . ' = ' . $groupId)
		)->loadResult()) {
			return ToolResult::error('User group ' . $groupId . ' not found.');
		}

		$before = $rules[$permission][$groupId] ?? null;

		if ($value === null) {
			unset($rules[$permission][$groupId]);
			if (empty($rules[$permission])) {
				unset($rules[$permission]);
			}
		} else {
			$rules[$permission][$groupId] = $value;
		}

		// Rules re-serialises in the same format the admin's Save produces.
		$serialized = (string) new Rules($rules);

		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName('#__assets'))
				->set($this->db->quoteName('rules') . ' = ' . $this->db->quote($serialized))
				->where($this->db->quoteName('id') . ' = ' . (int) $asset['id'])
		)->execute();

		Access::clearStatics();

		return ToolResult::json([
			'ok'             => true,
			'item_type'      => $type,
			'id'             => $id,
			'asset'          => $assetName,
			'group_id'       => $groupId,
			'permission'     => $permission,
			'value_before'   => $before,
			'value_after'    => $value,
			'resolved_after' => (bool) Access::checkGroup($groupId, $permission, $assetName),
		]);
	}

	/**
	 * @return array{0: string, 1: string, 2: string}|null [component, asset name, access.xml section]
	 */
	private function resolveItem(string $type, int $id): ?array
	{
		$db = $this->db;

		switch ($type) {
			case 'article':
				$found = (bool) $db->setQuery(
					$db->getQuery(true)->select('1')->from($db->quoteName('#__content'))
						->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
				)->loadResult();

				return $found ? ['com_content', 'com_content.article.' . $id, 'article'] : null;

			case 'category':
				$ext = (string) $db->setQuery(
					$db->getQuery(true)->select($db->quoteName('extension'))->from($db->quoteName('#__categories'))
						->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
				)->loadResult();

				return preg_match('/^com_[a-z0-9_]+$/', $ext) ? [$ext, $ext . '.category.' . $id, 'category'] : null;

			case 'module':
				$found = (bool) $db->setQuery(
					$db->getQuery(true)->select('1')->from($db->quoteName('#__modules'))
						->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
				)->loadResult();

				return $found ? ['com_modules', 'com_modules.module.' . $id, 'module'] : null;

			case 'field':
			case 'field_group':
				$context = (string) $db->setQuery(
					$db->getQuery(true)->select($db->quoteName('context'))
						->from($db->quoteName($type === 'field' ? '#__fields' : '#__fields_groups'))
						->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER)
				)->loadResult();
				$component = explode('.', $context)[0];

				if (!preg_match('/^com_[a-z0-9_]+$/', $component)) {
					return null;
				}

				return $type === 'field'
					? [$component, $component . '.field.' . $id, 'field']
					: [$component, $component . '.fieldgroup.' . $id, 'fieldgroup'];
		}

		return null;
	}

	/** @return string[] The action names the component's access.xml declares for this section. */
	private function sectionActions(string $component, string $section): array
	{
		$file    = JPATH_ADMINISTRATOR . '/components/' . $component . '/access.xml';
		$actions = is_file($file) ? Access::getActionsFromFile($file, "/access/section[@name='" . $section . "']/") : false;

		if (!$actions) {
			// Joomla's own fallback when a component declares nothing for fields.
			$actions = $section === 'field'
				? [(object) ['name' => 'core.delete'], (object) ['name' => 'core.edit'], (object) ['name' => 'core.edit.state'], (object) ['name' => 'core.edit.value']]
				: [(object) ['name' => 'core.delete'], (object) ['name' => 'core.edit'], (object) ['name' => 'core.edit.state']];
		}

		return array_values(array_map(static fn ($a): string => (string) $a->name, $actions));
	}

	private function loadAsset(string $name): ?array
	{
		$db = $this->db;

		return $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['id', 'rules']))
				->from($db->quoteName('#__assets'))
				->where($db->quoteName('name') . ' = :name')
				->bind(':name', $name)
		)->loadAssoc() ?: null;
	}
}
