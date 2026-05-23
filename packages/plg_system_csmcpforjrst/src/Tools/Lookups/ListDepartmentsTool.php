<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Lookups;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

final class ListDepartmentsTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'list_rst_departments'; }

	public function getDescription(): string
	{
		return 'List RSTicketsPro departments (#__rsticketspro_departments). Returns id, name, '
			. 'prefix (ticket code prefix), email_address, published, ordering, and the '
			. 'notify_new_tickets_to address list. Use the ids for filtering ticket queries '
			. 'or with update_rst_ticket(department_id=...).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'include_unpublished' => ['type' => 'boolean', 'description' => 'Default false. Set true to include unpublished departments.'],
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
		$includeUnpub = (bool) ($arguments['include_unpublished'] ?? false);
		$prefix = $this->db->getPrefix();

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'name', 'prefix', 'email_address', 'email_address_fullname', 'email_address_reply_to', 'notify_new_tickets_to', 'cc', 'bcc', 'published', 'ordering', 'priority_id', 'assignment_type', 'upload', 'upload_extensions', 'upload_size', 'upload_files']))
			->from($this->db->quoteName($prefix . 'rsticketspro_departments'))
			->order($this->db->quoteName('ordering') . ' ASC');
		if (!$includeUnpub) {
			$query->where($this->db->quoteName('published') . ' = 1');
		}
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			foreach (['id', 'published', 'ordering', 'priority_id', 'assignment_type', 'upload', 'upload_files'] as $k) {
				$r[$k] = (int) $r[$k];
			}
			$r['upload_size'] = (float) $r['upload_size'];
		}
		unset($r);

		return ToolResult::json(['ok' => true, 'count' => count($rows), 'departments' => $rows]);
	}
}
