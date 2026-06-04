<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Access\Access;
use Joomla\CMS\User\User;

/**
 * Returns the ACL state for a component asset: parsed #__assets.rules JSON
 * plus the resolved permission for every (group, permission) cell via
 * Access::checkGroup() — the same inheritance walk Joomla uses internally.
 *
 * The "resolved" column is what actually matters at runtime; the "explicit"
 * column reflects what's recorded in the asset row (vs. inherited from a
 * parent asset).
 *
 * Permissions are discovered from two sources:
 *   1. The component's access.xml (canonical list of supported actions)
 *   2. Any action keys present in the rules JSON (catches component-specific
 *      actions or rules added by an extension after install)
 */
final class ListComponentPermissionsTool extends AbstractTool
{
	private const COMMON_ACTIONS = [
		'core.admin', 'core.manage', 'core.options',
		'core.create', 'core.delete', 'core.edit', 'core.edit.state', 'core.edit.own',
		'core.execute',
	];

	public function getName(): string { return 'list_component_permissions'; }

	public function getDescription(): string
	{
		return 'Return the ACL state for a component or the root asset. Required: component '
			. '(e.g. "com_dpcalendar"; use "root.1" for the global config asset). For each '
			. 'discovered permission, returns explicit value (1=Allowed, 0=Denied, null=Inherited) '
			. 'AND resolved value (the effective permission after walking the asset inheritance '
			. 'chain — what Joomla actually checks at runtime). Diff the resolved values across '
			. 'groups to find which permission is gating access for a specific group.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['component'],
			'properties' => [
				'component' => ['type' => 'string', 'description' => 'Asset name. "com_xxx" for a component, "root.1" for the global config asset.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$component = $this->requireSafeAssetName($this->requireString($arguments, 'component'));

		$asset = $this->loadAsset($component);
		if ($asset === null) {
			return ToolResult::error('No #__assets row for "' . $component . '".');
		}

		$rules = json_decode((string) $asset['rules'], true);
		if (!is_array($rules)) {
			$rules = [];
		}

		$groups  = $this->loadGroups();
		$actions = $this->discoverActions($component, $rules);

		// Reset Access caches so changes from earlier MCP calls in this request
		// don't return stale resolved values.
		Access::clearStatics();

		$matrix = [];
		foreach ($actions as $action) {
			$row = ['permission' => $action, 'groups' => []];
			foreach ($groups as $g) {
				$gid = (int) $g['id'];
				$row['groups'][] = [
					'group_id' => $gid,
					'group'    => $g['title'],
					'explicit' => $rules[$action][$gid] ?? null,
					'resolved' => (bool) Access::checkGroup($gid, $action, $component),
				];
			}
			$matrix[] = $row;
		}

		return ToolResult::json([
			'asset_id'    => (int) $asset['id'],
			'asset_name'  => (string) $asset['name'],
			'asset_title' => (string) $asset['title'],
			'parent_id'   => (int) $asset['parent_id'],
			'rules'       => $rules,
			'permissions' => $matrix,
		]);
	}

	private function loadAsset(string $name): ?array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'name', 'title', 'parent_id', 'rules']))
			->from($this->db->quoteName('#__assets'))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($name));
		return $this->db->setQuery($q)->loadAssoc() ?: null;
	}

	/**
	 * @return array<int, array{id:int,title:string,parent_id:int}>
	 */
	private function loadGroups(): array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'parent_id']))
			->from($this->db->quoteName('#__usergroups'))
			->order($this->db->quoteName('lft') . ' ASC');
		return $this->db->setQuery($q)->loadAssocList() ?: [];
	}

	/**
	 * Combines actions declared in the component's access.xml with any actions
	 * already present in the asset's rules JSON, then falls back to the common
	 * core actions for components that don't ship access.xml.
	 *
	 * @return array<int, string>
	 */
	private function discoverActions(string $component, array $rules): array
	{
		$declared = [];
		if (str_starts_with($component, 'com_')) {
			$accessXml = JPATH_ADMINISTRATOR . '/components/' . $component . '/access.xml';
			if (is_file($accessXml)) {
				try {
					$xml = simplexml_load_file($accessXml);
					if ($xml !== false) {
						foreach ($xml->xpath('//action[@name]') ?: [] as $action) {
							$name = (string) $action['name'];
							if ($name !== '') {
								$declared[$name] = true;
							}
						}
					}
				} catch (\Throwable $e) {
					// Fall back to common actions silently.
				}
			}
		}

		$fromRules = array_fill_keys(array_keys($rules), true);
		$combined  = array_unique(array_merge(array_keys($declared), array_keys($fromRules)));

		if (empty($combined)) {
			$combined = self::COMMON_ACTIONS;
		}
		sort($combined);
		return $combined;
	}
}
