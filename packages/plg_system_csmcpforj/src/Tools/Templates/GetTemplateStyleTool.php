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
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Fetch a single template style's row with its params decoded from JSON into
 * a proper object. Counterpart to list_template_styles (which returns the list
 * without params). The AI calls this before update_template_style so it can
 * read current values, change only what it needs to, and send the full merged
 * object back.
 *
 * `home` is char(7) in #__template_styles: "0", "1", or on a multilingual site
 * a content-language code ("en-GB") for the style that is the default for that
 * language. Until 2026-10-20 it was cast to int, so a language default read
 * back as 0. It is now an int for "0"/"1" and the language code otherwise.
 * `parent` is varchar(50): the parent template's NAME for a child template
 * ("cassiopeia"), '' otherwise. It was cast to int too, so it always read 0.
 * `assigned_menu_item_ids` (site styles) is the Menu Assignment tab.
 */
final class GetTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'get_template_style'; }

	public function getDescription(): string
	{
		return 'Fetch a single template style by id. Returns the row including the params blob '
			. 'decoded into a JSON object (Cassiopeia colour scheme, sticky header, font choice, '
			. 'etc; param names vary per template), home (1 = default style, 0 = not, or a '
			. 'language code such as "en-GB" when it is the default for that language only), '
			. 'inheritable (1 = can have child templates) and parent (the parent template name for a '
			. 'child template, empty otherwise), and for site styles assigned_menu_item_ids '
			. '(the menu items that use this style). Call list_template_styles first to find the '
			. 'id you want. Pair with update_template_style to write changes back.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Template style id from list_template_styles.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params']))
			->from($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Template style ' . $id . ' not found.');
		}

		$home = (string) $row['home'];
		$home = ($home === '0' || $home === '1' || $home === '') ? (int) $home : $home;

		$assigned = null;
		if ((int) $row['client_id'] === 0) {
			$assigned = array_map('intval', $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('id'))
					->from($this->db->quoteName('#__menu'))
					->where($this->db->quoteName('template_style_id') . ' = ' . $id)
					->where($this->db->quoteName('client_id') . ' = 0')
					->order($this->db->quoteName('id'))
			)->loadColumn() ?: []);
		}

		// Decode params JSON into an associative array. If decoding fails (stored
		// as malformed JSON), surface the raw string so the AI can at least see it.
		$paramsRaw    = (string) ($row['params'] ?? '');
		$paramsParsed = $paramsRaw === '' ? new \stdClass() : json_decode($paramsRaw, true);
		if (!is_array($paramsParsed) && $paramsRaw !== '') {
			return ToolResult::json([
				'id'             => (int) $row['id'],
				'template'       => $row['template'],
				'client_id'      => (int) $row['client_id'],
				'home'           => $home,
				'title'          => $row['title'],
				'inheritable'    => (int) $row['inheritable'],
				'parent'         => (string) ($row['parent'] ?? ''),
				'params'         => null,
				'params_raw'     => $paramsRaw,
				'params_warning' => 'stored params did not decode as JSON; raw string returned for inspection',
			] + ($assigned === null ? [] : ['assigned_menu_item_ids' => $assigned]));
		}

		return ToolResult::json([
			'id'          => (int) $row['id'],
			'template'    => $row['template'],
			'client_id'   => (int) $row['client_id'],
			'home'        => $home,
			'title'       => $row['title'],
			'inheritable' => (int) $row['inheritable'],
			'parent'      => (string) ($row['parent'] ?? ''),
			'params'      => $paramsParsed,
		] + ($assigned === null ? [] : ['assigned_menu_item_ids' => $assigned]));
	}
}
