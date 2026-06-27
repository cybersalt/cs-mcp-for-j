<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Update a template style's params blob (and optionally the title). Equivalent
 * to clicking Save on a style under System → Site Template Styles. Pass-through
 * semantics — the AI is responsible for using the right param names for the
 * specific template (Cassiopeia exposes different params than Atum, etc.).
 *
 * Merge behaviour: the supplied params object is MERGED into the existing
 * params, not REPLACED. To unset a param, pass it with value null. This matches
 * what set_plugin_params does on the plugin params surface so the AI doesn't
 * have to learn two different write semantics.
 *
 * Does NOT touch home/inheritance/parent/assignment — set_default_template_style
 * is the home-flag tool; per-menu-item assignment is via the menu tools.
 */
final class UpdateTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'update_template_style'; }

	public function getDescription(): string
	{
		return 'Update a template style\'s params (and optionally its title). Equivalent to '
			. 'Save under System → Site Template Styles. Params are MERGED into the existing '
			. 'params object (not replaced) — pass only the keys you want to change. To unset '
			. 'a key, pass it with a null value. Param names vary per template (Cassiopeia has '
			. 'colourScheme/stickyHeader/etc.; Atum has different ones); call get_template_style '
			. 'first to see what\'s currently set. Does NOT change which style is the home/default '
			. '(use set_default_template_style for that) and does NOT change per-menu-item '
			. 'assignments (use the menu tools).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id'     => ['type' => 'integer', 'description' => 'Template style id from list_template_styles / get_template_style.'],
				'title'  => ['type' => 'string', 'description' => 'Optional. New style title.'],
				'params' => [
					'type'                 => 'object',
					'description'          => 'Optional. Params to merge into the existing params blob. Null values delete the key.',
					'additionalProperties' => true,
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'template', 'client_id', 'title', 'params']))
			->from($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Template style ' . $id . ' not found.');
		}

		$updates       = [];
		$newTitle      = isset($arguments['title']) ? trim((string) $arguments['title']) : null;
		$paramsPatch   = $arguments['params'] ?? null;
		$paramsChanged = false;

		if ($newTitle !== null && $newTitle !== '' && $newTitle !== (string) $row['title']) {
			$updates['title'] = $newTitle;
		}

		if (is_array($paramsPatch)) {
			$existing = json_decode((string) ($row['params'] ?? ''), true);
			if (!is_array($existing)) {
				$existing = [];
			}
			$merged = $existing;
			foreach ($paramsPatch as $key => $value) {
				if ($value === null) {
					unset($merged[$key]);
				} else {
					$merged[$key] = $value;
				}
			}
			$updates['params'] = (new Registry($merged))->toString();
			$paramsChanged     = true;
		}

		if ($updates === []) {
			return ToolResult::json([
				'ok'      => true,
				'id'      => $id,
				'noop'    => true,
				'message' => 'No changes supplied (omit title and params to call no-op intentionally).',
			]);
		}

		$update = $this->db->getQuery(true)->update($this->db->quoteName('#__template_styles'));
		foreach ($updates as $col => $val) {
			$update->set($this->db->quoteName($col) . ' = ' . $this->db->quote($val));
		}
		$update->where($this->db->quoteName('id') . ' = ' . $id);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'             => true,
			'id'             => $id,
			'template'       => $row['template'],
			'client_id'      => (int) $row['client_id'],
			'title_changed'  => isset($updates['title']),
			'params_changed' => $paramsChanged,
		]);
	}
}
