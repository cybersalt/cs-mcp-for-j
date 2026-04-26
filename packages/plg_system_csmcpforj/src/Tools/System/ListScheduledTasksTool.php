<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListScheduledTasksTool extends AbstractTool
{
	public function getName(): string { return 'list_scheduled_tasks'; }

	public function getDescription(): string
	{
		return 'List Joomla scheduled tasks (System → Manage → Scheduled Tasks). Returns id, '
			. 'title, type, state, last_exit_code, next_execution, last_execution.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'state' => ['type' => 'integer', 'enum' => [-2, 0, 1, 2]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'type', 'state', 'last_exit_code', 'next_execution', 'last_execution', 'priority']))
			->from($this->db->quoteName('#__scheduler_tasks'))
			->order($this->db->quoteName('priority') . ' DESC, ' . $this->db->quoteName('title'));

		if (isset($arguments['state'])) {
			$query->where($this->db->quoteName('state') . ' = ' . (int) $arguments['state']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'tasks' => $rows]);
	}
}
