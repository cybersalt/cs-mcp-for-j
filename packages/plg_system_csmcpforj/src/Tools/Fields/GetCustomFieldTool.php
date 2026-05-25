<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetCustomFieldTool extends AbstractTool
{
	public function getName(): string { return 'get_custom_field'; }

	public function getDescription(): string
	{
		return 'Fetch a single custom field by id. Returns every column on #__fields with '
			. 'fieldparams + params decoded from JSON, plus the M:N-joined assigned_cat_ids '
			. '(category ids from #__fields_categories — restricts which articles the field '
			. 'shows on; empty array means "all categories"; [-1] means "no categories" — see '
			. 'update_custom_field for the semantics).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__fields'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Custom field ' . $id . ' not found.');
		}
		$row['params']      = $row['params'] ? json_decode((string) $row['params'], true) : null;
		$row['fieldparams'] = $row['fieldparams'] ? json_decode((string) $row['fieldparams'], true) : null;

		// assigned_cat_ids lives in the M:N join table #__fields_categories, not on
		// the field row itself. An empty list here means "all categories" (the default
		// — no restrictions). [-1] is Joomla's "no categories" sentinel that the admin
		// UI writes when a user picks "None" in the category multi-select.
		$catQuery = $this->db->getQuery(true)
			->select($this->db->quoteName('category_id'))
			->from($this->db->quoteName('#__fields_categories'))
			->where($this->db->quoteName('field_id') . ' = ' . $id)
			->order($this->db->quoteName('category_id'));
		$catIds = $this->db->setQuery($catQuery)->loadColumn() ?: [];
		$row['assigned_cat_ids'] = array_map('intval', $catIds);

		return ToolResult::json($row);
	}
}
