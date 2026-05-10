<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * MERGE-update of com_forseo's params. Pass only the keys you want to change;
 * existing keys are preserved. Replaces the entire stored params if mode=replace
 * is passed — useful when you genuinely want to wipe everything.
 *
 * Cached ComponentHelper params are NOT invalidated; call clear_cache after
 * if subsequent same-request reads matter.
 */
final class Set4seoComponentParamsTool extends AbstractTool
{
	public function getName(): string { return 'set_4seo_component_params'; }

	public function getDescription(): string
	{
		return 'Modify com_forseo component params (Options screen). Default mode is "merge": '
			. 'only the keys you supply are changed, the rest is preserved. Set mode="replace" '
			. 'to overwrite the entire params object with what you supply (rarely what you want).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['params'],
			'properties' => [
				'params' => ['type' => 'object', 'description' => 'Key-value map of params to set.'],
				'mode'   => ['type' => 'string', 'enum' => ['merge', 'replace'], 'description' => 'Default merge.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$incoming = $arguments['params'] ?? null;
		if (!is_array($incoming)) {
			return ToolResult::error('params must be an object.');
		}
		$mode = (string) ($arguments['mode'] ?? 'merge');
		if (!in_array($mode, ['merge', 'replace'], true)) {
			return ToolResult::error('mode must be merge or replace.');
		}

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_forseo'));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('com_forseo is not installed on this site.');
		}

		$existing = $row['params'] ? json_decode((string) $row['params'], true) : [];
		$existing = is_array($existing) ? $existing : [];

		$merged = $mode === 'replace' ? $incoming : array_replace_recursive($existing, $incoming);

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('params') . ' = ' . $this->db->quote(json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
			->where($this->db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'     => true,
			'mode'   => $mode,
			'changed_keys' => array_keys($incoming),
			'params' => $merged,
		]);
	}
}
