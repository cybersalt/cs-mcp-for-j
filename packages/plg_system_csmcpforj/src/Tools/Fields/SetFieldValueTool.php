<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * Sets a custom field's value on a specific item (e.g. an article). Uses
 * com_fields' FieldsHelper::setFieldValue under the hood when available, or
 * falls back to a direct upsert into #__fields_values.
 */
final class SetFieldValueTool extends AbstractTool
{
	public function getName(): string { return 'set_custom_field_value'; }

	public function getDescription(): string
	{
		return 'Set a custom field value on an item (article/user/contact/...). Required: '
			. 'field_id, item_id, value. Use list_custom_fields to find a field id and its '
			. 'context — the item_id is the id of the row in that context (e.g. #__content.id '
			. 'for com_content.article).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['field_id', 'item_id', 'value'],
			'properties' => [
				'field_id' => ['type' => 'integer'],
				'item_id'  => ['type' => 'integer'],
				'value'    => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$fieldId = $this->requirePositiveInt($arguments, 'field_id');
		$itemId  = $this->requirePositiveInt($arguments, 'item_id');
		$value   = (string) ($arguments['value'] ?? '');

		$existsQuery = $this->db->getQuery(true)
			->select('1')
			->from($this->db->quoteName('#__fields_values'))
			->where($this->db->quoteName('field_id') . ' = ' . $fieldId)
			->where($this->db->quoteName('item_id') . ' = ' . $this->db->quote((string) $itemId));
		$exists = (bool) $this->db->setQuery($existsQuery)->loadResult();

		if ($exists) {
			$update = $this->db->getQuery(true)
				->update($this->db->quoteName('#__fields_values'))
				->set($this->db->quoteName('value') . ' = ' . $this->db->quote($value))
				->where($this->db->quoteName('field_id') . ' = ' . $fieldId)
				->where($this->db->quoteName('item_id') . ' = ' . $this->db->quote((string) $itemId));
			$this->db->setQuery($update)->execute();
		} else {
			$insert = $this->db->getQuery(true)
				->insert($this->db->quoteName('#__fields_values'))
				->columns($this->db->quoteName(['field_id', 'item_id', 'value']))
				->values($fieldId . ', ' . $this->db->quote((string) $itemId) . ', ' . $this->db->quote($value));
			$this->db->setQuery($insert)->execute();
		}

		return ToolResult::json(['ok' => true, 'field_id' => $fieldId, 'item_id' => $itemId]);
	}
}
