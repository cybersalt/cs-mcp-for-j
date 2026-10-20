<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Update a template style: title, params, the Default ("home") setting and the
 * Menu Assignment tab. Equivalent to clicking Save on a style under System →
 * Site/Administrator Template Styles. Pass-through semantics for params — the
 * AI is responsible for using the right param names for the specific template
 * (Cassiopeia exposes different params than Atum, etc.).
 *
 * Merge behaviour: the supplied params object is MERGED into the existing
 * params, not REPLACED. To unset a param, pass it with value null. This matches
 * what set_plugin_params does on the plugin params surface so the AI doesn't
 * have to learn two different write semantics.
 *
 * Field parity with Joomla 6.2.0's style edit screen (com_templates/forms/
 * style.xml + style_site.xml / style_administrator.xml + the template's own
 * templateDetails.xml <config>), reviewed 2026-10-20:
 *   - title, params: as before.
 *   - home (added 2026-10-20): "1" default for all languages, "0" not a
 *     default, or (site styles on a multilingual site) a content-language code
 *     to make it the default for that language. Mirrors StyleTable::bind() and
 *     ::store(): the all-languages default cannot be switched off here (make
 *     another style the default instead), and setting a value clears it from
 *     whichever other style of the same client held it.
 *   - assigned_menu_item_ids (added 2026-10-20, site styles): the Menu
 *     Assignment tab, with StyleModel::save()'s replace semantics, but ONLY
 *     when supplied (see below). Menu items checked out by someone else are
 *     skipped, as Joomla skips them, and reported.
 *   - Not exposed: template and client_id (read-only on the edit screen),
 *     inheritable and parent (hidden read-only fields the installer sets for
 *     child templates; get_template_style returns them).
 *
 * @partial-save-safe Safe because this tool writes #__template_styles
 * DIRECTLY and never calls StyleModel::save(). Keep it that way: for a site
 * style, StyleModel::save() rewrites #__menu.template_style_id from
 * $data['assigned'] and, when that key is missing, removes the style from
 * EVERY menu item it was assigned to ("If unassigned then all existing maps
 * will be removed"). Routing this tool through the model would silently
 * reassign those pages to the default template. Verified behaviourally on
 * Joomla 6.1.4, 2026-10-09: changed only `title`, diffed all 8 fields,
 * restored the title, nothing else moved; reason re-checked 2026-10-15
 * against the model source during the related-table sweep, and again
 * 2026-10-20 against 6.2.0 when home and assigned_menu_item_ids were added:
 * each column is written only when its argument is supplied, and #__menu is
 * touched only when assigned_menu_item_ids is supplied.
 */
final class UpdateTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'update_template_style'; }

	public function getDescription(): string
	{
		return 'Update a template style\'s title, params, default setting or menu assignment. '
			. 'Equivalent to Save under System → Site/Administrator Template Styles. Params are '
			. 'MERGED into the existing params object (not replaced) — pass only the keys you want '
			. 'to change. To unset a key, pass it with a null value. Param names vary per template '
			. '(Cassiopeia has colourScheme/stickyHeader/etc.; Atum has different ones); call '
			. 'get_template_style first to see what\'s currently set. home: "1" makes it the '
			. 'default for all languages (same as set_default_template_style), a language code such '
			. 'as "en-GB" makes a site style the default for that language on a multilingual site, '
			. '"0" removes a per-language default (the all-languages default cannot be removed — '
			. 'make another style the default instead). assigned_menu_item_ids (site styles only) '
			. 'REPLACES the list of menu items that use this style, like the Menu Assignment tab: '
			. 'items left out go back to the default style; [] unassigns all; omit it to leave '
			. 'assignments alone. Per-item assignment is also possible with update_menu_item '
			. '(template_style_id).';
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
				'home'   => [
					'type'        => 'string',
					'description' => 'Optional. "1" default for all languages, "0" not a default, or a content language code (e.g. "en-GB", site styles only) to make it that language\'s default.',
				],
				'assigned_menu_item_ids' => [
					'type'        => 'array',
					'items'       => ['type' => 'integer'],
					'description' => 'Optional, site styles only. The complete list of menu item ids that should use this style; replaces the current list. [] unassigns all.',
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
			->select($this->db->quoteName(['id', 'template', 'client_id', 'home', 'title', 'params']))
			->from($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Template style ' . $id . ' not found.');
		}

		$clientId      = (int) $row['client_id'];
		$updates       = [];
		$newTitle      = isset($arguments['title']) ? trim((string) $arguments['title']) : null;
		$paramsPatch   = $arguments['params'] ?? null;
		$paramsChanged = false;
		$homeReset     = null;

		if ($newTitle !== null && $newTitle !== '' && $newTitle !== (string) $row['title']) {
			$updates['title'] = $newTitle;
		}

		if (is_array($paramsPatch)) {
			$updates['params'] = (new Registry($this->mergeJsonPatch((string) ($row['params'] ?? ''), $paramsPatch)))->toString();
			$paramsChanged     = true;
		}

		if (array_key_exists('home', $arguments)) {
			$home = $this->validateHome($arguments['home'], $row);
			if ($home !== (string) $row['home']) {
				$updates['home'] = $home;
				// StyleTable::store(): the value moves, it is never shared.
				$homeReset = $home !== '0' ? $home : null;
			}
		}

		$assigned = null;
		if (array_key_exists('assigned_menu_item_ids', $arguments)) {
			$assigned = $this->validateAssignment($arguments['assigned_menu_item_ids'], $clientId);
		}

		if ($updates === [] && $assigned === null) {
			return ToolResult::json([
				'ok'      => true,
				'id'      => $id,
				'noop'    => true,
				'message' => 'No changes supplied (omit title, params, home and assigned_menu_item_ids to call no-op intentionally).',
			]);
		}

		$assignResult = null;

		$this->db->transactionStart();
		try {
			if ($homeReset !== null) {
				$this->db->setQuery(
					$this->db->getQuery(true)
						->update($this->db->quoteName('#__template_styles'))
						->set($this->db->quoteName('home') . ' = ' . $this->db->quote('0'))
						->where($this->db->quoteName('client_id') . ' = ' . $clientId)
						->where($this->db->quoteName('home') . ' = ' . $this->db->quote($homeReset))
						->where($this->db->quoteName('id') . ' != ' . $id)
				)->execute();
			}

			if ($updates !== []) {
				$update = $this->db->getQuery(true)->update($this->db->quoteName('#__template_styles'));
				foreach ($updates as $col => $val) {
					$update->set($this->db->quoteName($col) . ' = ' . $this->db->quote($val));
				}
				$update->where($this->db->quoteName('id') . ' = ' . $id);
				$this->db->setQuery($update)->execute();
			}

			if ($assigned !== null) {
				$assignResult = $this->writeAssignment($id, $assigned, (int) $actor->id);
			}

			$this->db->transactionCommit();
		} catch (\Throwable $e) {
			$this->db->transactionRollback();
			throw $e;
		}

		$response = [
			'ok'             => true,
			'id'             => $id,
			'template'       => $row['template'],
			'client_id'      => $clientId,
			'title_changed'  => isset($updates['title']),
			'params_changed' => $paramsChanged,
			'home_changed'   => isset($updates['home']),
		];
		if (isset($updates['home'])) {
			$response['home'] = $updates['home'];
		}
		if ($assignResult !== null) {
			$response += $assignResult;
		}

		return ToolResult::json($response);
	}

	/**
	 * home is char(7): '0', '1', or a content-language code (site styles on a
	 * multilingual site). Mirrors the checks Joomla 6.2 makes on the edit screen
	 * and in StyleTable / StyleModel::setHome().
	 */
	private function validateHome(mixed $value, array $row): string
	{
		if (!is_scalar($value)) {
			throw new McpException(-32602, 'home must be "1", "0" or a content language code such as "en-GB".');
		}

		$home    = trim((string) $value);
		$current = (string) $row['home'];

		if ($home === $current) {
			return $home;
		}

		if ($home === '') {
			throw new McpException(-32602, 'home must be "1", "0" or a content language code such as "en-GB".');
		}

		if ($current === '1') {
			throw new McpException(-32602, 'Style ' . $row['id'] . ' is the default style for all languages; that cannot be '
				. 'switched off or narrowed to one language. Make another style the default with '
				. 'set_default_template_style instead.');
		}

		if ($home === '0') {
			return '0';
		}

		if ($home !== '1') {
			if ((int) $row['client_id'] !== 0) {
				throw new McpException(-32602, 'Administrator template styles can only have home "1" or "0".');
			}

			$known = (int) $this->db->setQuery(
				$this->db->getQuery(true)
					->select('COUNT(*)')
					->from($this->db->quoteName('#__languages'))
					->where($this->db->quoteName('lang_code') . ' = ' . $this->db->quote($home))
			)->loadResult();
			if ($known === 0) {
				throw new McpException(-32602, 'home: no content language "' . $home . '" exists on this site. '
					. 'Use list_content_languages to see the language codes.');
			}
		}

		// StyleModel::setHome() and save() both refuse a disabled template.
		$enabled = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('enabled'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('template'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote((string) $row['template']))
				->where($this->db->quoteName('client_id') . ' = ' . (int) $row['client_id'])
		)->loadResult();
		if ($enabled !== null && (int) $enabled === 0) {
			throw new McpException(-32602, 'The template "' . $row['template'] . '" is disabled, so this style cannot be '
				. 'made a default. Enable the template first (set_extension_enabled).');
		}

		return $home;
	}

	/** @return int[] */
	private function validateAssignment(mixed $value, int $clientId): array
	{
		if ($clientId !== 0) {
			throw new McpException(-32602, 'assigned_menu_item_ids applies to site template styles only.');
		}
		if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
			throw new McpException(-32602, 'assigned_menu_item_ids must be an array of menu item ids ([] unassigns all).');
		}

		$ids = [];
		foreach ($value as $v) {
			if (!is_numeric($v) || (int) $v <= 0) {
				throw new McpException(-32602, 'assigned_menu_item_ids must contain only positive menu item ids; got ' . json_encode($v) . '.');
			}
			$ids[(int) $v] = (int) $v;
		}
		$ids = array_values($ids);

		if ($ids !== []) {
			$found = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('id'))
					->from($this->db->quoteName('#__menu'))
					->whereIn($this->db->quoteName('id'), $ids)
					->where($this->db->quoteName('client_id') . ' = 0')
			)->loadColumn() ?: []);
			$missing = array_values(array_diff($ids, $found));
			if ($missing !== []) {
				throw new McpException(-32602, 'assigned_menu_item_ids: no site menu item with id ' . implode(', ', $missing)
					. '. Use list_menu_items to find the ids.');
			}
		}

		return $ids;
	}

	/**
	 * The Menu Assignment write from StyleModel::save() (Joomla 6.2.0): point
	 * the listed items at this style, and send every other item that used it
	 * back to the default (template_style_id 0). Items checked out by another
	 * user are left alone, as Joomla leaves them.
	 */
	private function writeAssignment(int $styleId, array $ids, int $actorId): array
	{
		$db        = $this->db;
		$notLocked = '(' . $db->quoteName('checked_out') . ' IS NULL OR ' . $db->quoteName('checked_out') . ' = 0 OR '
			. $db->quoteName('checked_out') . ' = ' . $actorId . ')';

		$assignedCount = 0;
		if ($ids !== []) {
			$db->setQuery(
				$db->getQuery(true)
					->update($db->quoteName('#__menu'))
					->set($db->quoteName('template_style_id') . ' = ' . $styleId)
					->whereIn($db->quoteName('id'), $ids)
					->where($db->quoteName('template_style_id') . ' != ' . $styleId)
					->where($notLocked)
			)->execute();
			$assignedCount = (int) $db->getAffectedRows();
		}

		$unassign = $db->getQuery(true)
			->update($db->quoteName('#__menu'))
			->set($db->quoteName('template_style_id') . ' = 0')
			->where($db->quoteName('template_style_id') . ' = ' . $styleId)
			->where($db->quoteName('client_id') . ' = 0')
			->where($notLocked);
		if ($ids !== []) {
			$unassign->whereNotIn($db->quoteName('id'), $ids);
		}
		$db->setQuery($unassign)->execute();
		$unassignedCount = (int) $db->getAffectedRows();

		$skipped = [];
		if ($ids !== []) {
			$skipped = array_map('intval', $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('id'))
					->from($db->quoteName('#__menu'))
					->whereIn($db->quoteName('id'), $ids)
					->where($db->quoteName('template_style_id') . ' != ' . $styleId)
			)->loadColumn() ?: []);
		}

		$result = [
			'menu_items_assigned'   => $assignedCount,
			'menu_items_unassigned' => $unassignedCount,
		];
		if ($skipped !== []) {
			$result['menu_items_skipped_checked_out'] = $skipped;
		}

		return $result;
	}
}
