<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Custom fields defined per department. The values for a specific ticket
 * are included in get_rst_ticket\'s custom_fields[] output.
 */
final class ListCustomFieldsTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_custom_fields'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro custom fields (#__rsticketspro_custom_fields). Optional filter: '
			. 'department_id. Each row: id, department_id, name (internal key), label (display), '
			. 'type (text/textarea/select/checkbox/etc.), values (for select/radio types — '
			. 'pipe-delimited usually), required, published.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'department_id'       => ['type' => 'integer'],
				'include_unpublished' => ['type' => 'boolean'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}
		$prefix = $this->db->getPrefix();
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'department_id', 'name', 'label', 'type', 'values', 'additional', 'validation', 'required', 'description', 'published', 'ordering']))
			->from($this->db->quoteName($prefix . 'rsticketspro_custom_fields'))
			->order($this->db->quoteName('department_id') . ' ASC')
			->order($this->db->quoteName('ordering') . ' ASC');
		if (array_key_exists('department_id', $arguments)) {
			$query->where($this->db->quoteName('department_id') . ' = ' . (int) $arguments['department_id']);
		}
		if (empty($arguments['include_unpublished'])) {
			$query->where($this->db->quoteName('published') . ' = 1');
		}
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			foreach (['id', 'department_id', 'required', 'published', 'ordering'] as $k) {
				$r[$k] = (int) $r[$k];
			}
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'custom_fields' => $rows]);
	}
}
