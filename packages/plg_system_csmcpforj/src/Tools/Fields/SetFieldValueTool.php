<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Sets a custom field's value on a specific item (e.g. an article) by writing
 * #__fields_values directly, the table Joomla's FieldModel::setFieldValue()
 * writes.
 *
 * Joomla stores one row per value: a text field has one row, a checkboxes
 * field or a multiple-select list has one row per ticked option. So:
 *   - `value` (a string) sets a single-value field. With exactly one stored
 *     row it is updated in place; with none a row is inserted. If the item
 *     had SEVERAL rows (a multi-value field), they are replaced by the one
 *     value. Until 2026-10-20 every row was overwritten with the same value,
 *     leaving duplicates behind.
 *   - `values` (an array of strings) replaces all rows with one row per
 *     value, as FieldModel::setFieldValue() does for multiple values. An
 *     empty array removes the item's value, so the field falls back to its
 *     default value, as when the box is cleared on the edit screen.
 */
final class SetFieldValueTool extends AbstractTool
{
	public function getName(): string { return 'set_custom_field_value'; }

	public function getDescription(): string
	{
		return 'Set a custom field value on an item (article/user/contact/...). Required: '
			. 'field_id, item_id, and either value (a string, for single-value fields) or values '
			. '(an array of strings, for checkboxes, multiple-select lists and other multi-value '
			. 'fields; replaces every stored value; [] removes the value so the field falls back to '
			. 'its default). Subform values are one JSON string in value. Use list_custom_fields to '
			. 'find a field id and its context — the item_id is the id of the row in that context '
			. '(e.g. #__content.id for com_content.article).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['field_id', 'item_id'],
			'properties' => [
				'field_id' => ['type' => 'integer'],
				'item_id'  => ['type' => 'integer'],
				'value'    => ['type' => 'string', 'description' => 'The value, for a single-value field. Give this or values, not both.'],
				'values'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'All the values, for a multi-value field (one per ticked option). Replaces every stored value; [] removes the value.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$fieldId = $this->requirePositiveInt($arguments, 'field_id');
		$itemId  = $this->requirePositiveInt($arguments, 'item_id');

		$hasValue  = array_key_exists('value', $arguments);
		$hasValues = array_key_exists('values', $arguments);
		if ($hasValue === $hasValues) {
			throw new McpException(-32602, 'Give either value (a string) or values (an array of strings), not both and not neither.');
		}

		$field = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName(['id', 'type', 'context']))
				->from($this->db->quoteName('#__fields'))
				->where($this->db->quoteName('id') . ' = ' . $fieldId)
		)->loadAssoc();
		if (!$field) {
			return ToolResult::error('Custom field ' . $fieldId . ' not found.');
		}

		if ($hasValues) {
			if (!is_array($arguments['values']) || !array_is_list($arguments['values'])) {
				throw new McpException(-32602, 'values must be an array of strings.');
			}
			$values = [];
			foreach ($arguments['values'] as $v) {
				if (!is_scalar($v)) {
					throw new McpException(-32602, 'values must contain only strings; got ' . json_encode($v) . '.');
				}
				$values[] = (string) $v;
			}
		} else {
			if (!is_scalar($arguments['value']) && $arguments['value'] !== null) {
				throw new McpException(-32602, 'value must be a string; for several values use values.');
			}
			$values = [(string) ($arguments['value'] ?? '')];
		}

		$where = [
			$this->db->quoteName('field_id') . ' = ' . $fieldId,
			$this->db->quoteName('item_id') . ' = ' . $this->db->quote((string) $itemId),
		];

		$existingRows = (int) $this->db->setQuery(
			$this->db->getQuery(true)
				->select('COUNT(*)')
				->from($this->db->quoteName('#__fields_values'))
				->where($where)
		)->loadResult();

		$this->db->transactionStart();
		try {
			if (!$hasValues && $existingRows === 1) {
				// Single value over a single stored row: update in place.
				$this->db->setQuery(
					$this->db->getQuery(true)
						->update($this->db->quoteName('#__fields_values'))
						->set($this->db->quoteName('value') . ' = ' . $this->db->quote($values[0]))
						->where($where)
				)->execute();
			} else {
				if ($existingRows > 0) {
					$this->db->setQuery(
						$this->db->getQuery(true)
							->delete($this->db->quoteName('#__fields_values'))
							->where($where)
					)->execute();
				}
				foreach ($values as $v) {
					$this->db->setQuery(
						$this->db->getQuery(true)
							->insert($this->db->quoteName('#__fields_values'))
							->columns($this->db->quoteName(['field_id', 'item_id', 'value']))
							->values($fieldId . ', ' . $this->db->quote((string) $itemId) . ', ' . $this->db->quote($v))
					)->execute();
				}
			}
			$this->db->transactionCommit();
		} catch (\Throwable $e) {
			$this->db->transactionRollback();
			throw $e;
		}

		// FieldsHelper caches field values per request; clear it so anything
		// later in this request (another tool in a batch) sees the new value.
		$helper = 'Joomla\\Component\\Fields\\Administrator\\Helper\\FieldsHelper';
		if (class_exists($helper) && method_exists($helper, 'clearFieldsCache')) {
			$helper::clearFieldsCache();
		}

		return ToolResult::json([
			'ok'           => true,
			'field_id'     => $fieldId,
			'item_id'      => $itemId,
			'context'      => $field['context'],
			'values_count' => count($values),
		]);
	}
}
