<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;

/**
 * Argument checks shared by the custom-field and field-group create/update
 * tools: the four publishing states, the "Created by" user and the "Created
 * date" on the Publishing tab. Only the tools in this folder use it.
 *
 * Needs $this->db, so it can only be used by an AbstractTool subclass.
 */
trait FieldsArgumentsTrait
{
	/**
	 * state: 1 published, 0 unpublished, 2 archived, -2 trashed (the Status
	 * list on Joomla 6.2's field and field-group edit screens).
	 */
	private function fieldState(mixed $value): int
	{
		if (!is_numeric($value) || !in_array((int) $value, [1, 0, 2, -2], true)) {
			throw new McpException(-32602, 'state must be 1 (published), 0 (unpublished), 2 (archived) or -2 (trashed); got ' . json_encode($value) . '.');
		}

		return (int) $value;
	}

	/** The "Created by" user: must be the id of a user that exists. */
	private function fieldCreatorId(mixed $value, string $argName): int
	{
		$id = is_numeric($value) ? (int) $value : 0;
		if ($id <= 0 || (string) $id !== trim((string) $value)) {
			throw new McpException(-32602, $argName . ' must be a positive user id; got ' . json_encode($value) . '. Use list_users to find one.');
		}

		$exists = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__users'))
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->loadResult();

		if ($exists === 0) {
			throw new McpException(-32602, $argName . ': no user with id ' . $id . ' exists on this site. Use list_users to find one.');
		}

		return $id;
	}

	/**
	 * The "Created date", given in UTC as "YYYY-MM-DD HH:MM:SS" (or "YYYY-MM-DD",
	 * or ISO 8601 with an offset, which is converted to UTC). Returned in the
	 * SQL format Joomla stores.
	 */
	private function fieldCreatedDate(mixed $value, string $argName): string
	{
		$text = is_string($value) ? trim($value) : '';
		if ($text === '' || !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $text)) {
			throw new McpException(-32602, $argName . ' must be a UTC date and time such as "2026-10-20 14:30:00"; got ' . json_encode($value) . '.');
		}

		try {
			$date = new \DateTimeImmutable($text, new \DateTimeZone('UTC'));
		} catch (\Exception) {
			throw new McpException(-32602, $argName . ' is not a valid date: ' . json_encode($value) . '.');
		}

		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/**
	 * Schema for a custom field's `params`: the Options tab of Joomla 6.2's
	 * field edit screen (com_fields/forms/field.xml). The keys are spelled out in
	 * the description rather than as typed sub-properties, so a null (delete the
	 * key) is never refused by a strict client, and so there is no enum: show_on
	 * uses "" for "both", and an empty enum member breaks Gemini clients (see
	 * tests/enum-guard.php). Other keys are allowed; fields plugins add their own.
	 */
	private function customFieldParamsSchema(string $lead): array
	{
		return [
			'type'                 => 'object',
			'description'          => $lead . ' Keys (values as strings, as Joomla stores them): '
				. 'hint (placeholder in the edit form; not used by list fields), class (CSS class on the input), '
				. 'label_class (CSS class on the label in the form), show_on ("1" editable on the site only, '
				. '"2" in the administrator only, empty for both), showon (show-on rule such as "other-field:1"), '
				. 'render_class (CSS class on the displayed field), value_render_class (CSS class on the value), '
				. 'showlabel ("1" show the label, "0" hide it), label_render_class, display ("1" after title, '
				. '"2" before display, "3" after display, "0" no automatic display), prefix, suffix, layout '
				. '(layout override; empty for the default), display_readonly ("2" inherit, "1" yes, "0" no), '
				. 'searchindex (Smart Search: "0" no, "1" searchable, "2" taxonomy, "3" both).',
			'additionalProperties' => true,
		];
	}

	/**
	 * A params-style argument must be a JSON object. An empty JSON array
	 * decodes to [] in PHP, which is accepted as "no keys".
	 */
	private function fieldObjectArg(mixed $value, string $argName): array
	{
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new McpException(-32602, $argName . ' must be an object of keys and values; got ' . json_encode($value) . '.');
		}

		return $value;
	}
}
