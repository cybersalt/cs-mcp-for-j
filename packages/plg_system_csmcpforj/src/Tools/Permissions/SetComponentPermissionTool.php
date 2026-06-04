<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Rules;
use Joomla\CMS\User\User;

/**
 * Set or clear one ACL cell on a component (or root) asset.
 *
 * Equivalent to clicking a single row/column intersection on the Permissions
 * tab and hitting Save. Modifies #__assets.rules JSON in place.
 *
 * Refuses one specific footgun: setting core.admin Denied for Super Users
 * (group 8 by default) on the root.1 asset would lock everyone out of the
 * site. Other dangerous combinations are allowed because Joomla itself
 * allows them — be deliberate.
 */
final class SetComponentPermissionTool extends AbstractTool
{
	public function getName(): string { return 'set_component_permission'; }

	public function getDescription(): string
	{
		return 'Set one ACL cell on a component or the root asset. Required: component '
			. '(e.g. "com_dpcalendar" or "root.1"), group_id, permission (e.g. "core.admin", '
			. '"core.manage", "core.options"), value (1=Allowed, 0=Denied, null=Inherited — '
			. 'pass JSON null to clear the explicit setting and let the parent asset decide). '
			. 'Use list_component_permissions first to see the current state and discover the '
			. 'permission names this component supports.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['component', 'group_id', 'permission'],
			'properties' => [
				'component'  => ['type' => 'string'],
				'group_id'   => ['type' => 'integer'],
				'permission' => ['type' => 'string'],
				'value'      => ['type' => ['integer', 'null'], 'enum' => [1, 0, null], 'description' => '1=Allowed, 0=Denied, null=Inherited. Defaults to 1 if omitted.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component  = $this->requireSafeAssetName($this->requireString($arguments, 'component'));
		$groupId    = $this->requirePositiveInt($arguments, 'group_id');
		$permission = $this->requireString($arguments, 'permission');
		$value      = array_key_exists('value', $arguments) ? $arguments['value'] : 1;

		if ($value !== null && (int) $value !== 0 && (int) $value !== 1) {
			return ToolResult::error('value must be 1 (Allowed), 0 (Denied), or null (Inherited).');
		}

		// Footgun guard: lock-out-the-site combo on the root asset.
		if ($component === 'root.1' && $permission === 'core.admin' && (int) $groupId === 8 && $value === 0) {
			return ToolResult::error(
				'Refusing to set core.admin Denied for Super Users on root.1 — that would '
				. 'lock everyone out of the site. If this is genuinely what you want, do it '
				. 'directly in the admin GUI where you can see the warning banners.'
			);
		}

		if (!$this->groupExists($groupId)) {
			return ToolResult::error('User group ' . $groupId . ' not found.');
		}

		$asset = $this->loadAsset($component);
		if ($asset === null) {
			return ToolResult::error('No #__assets row for "' . $component . '".');
		}

		$rules = json_decode((string) $asset['rules'], true);
		if (!is_array($rules)) {
			$rules = [];
		}

		$before = $rules[$permission][$groupId] ?? null;

		if ($value === null) {
			if (isset($rules[$permission])) {
				unset($rules[$permission][$groupId]);
				if (empty($rules[$permission])) {
					unset($rules[$permission]);
				}
			}
		} else {
			if (!isset($rules[$permission]) || !is_array($rules[$permission])) {
				$rules[$permission] = [];
			}
			$rules[$permission][$groupId] = (int) $value;
		}

		// Use Joomla's Rules class to re-serialize — keeps the format identical
		// to what the GUI produces (sorted keys, etc.).
		$serialized = (string) (new Rules($rules));

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__assets'))
			->set($this->db->quoteName('rules') . ' = ' . $this->db->quote($serialized))
			->where($this->db->quoteName('id') . ' = ' . (int) $asset['id']);
		$this->db->setQuery($update)->execute();

		// Bust the Access::* statics so callers re-checking via list_component_permissions
		// in the same request see the new state.
		Access::clearStatics();

		return ToolResult::json([
			'ok'             => true,
			'component'      => $component,
			'group_id'       => $groupId,
			'permission'     => $permission,
			'value_before'   => $before,
			'value_after'    => $value === null ? null : (int) $value,
			'resolved_after' => (bool) Access::checkGroup($groupId, $permission, $component),
		]);
	}

	private function loadAsset(string $name): ?array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'name', 'rules']))
			->from($this->db->quoteName('#__assets'))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($name));
		return $this->db->setQuery($q)->loadAssoc() ?: null;
	}

	private function groupExists(int $id): bool
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__usergroups'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		return (bool) $this->db->setQuery($q)->loadResult();
	}
}
