<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListAccessLevelsTool extends AbstractTool
{
	public function getName(): string { return 'list_access_levels'; }

	public function getDescription(): string
	{
		return 'List viewing access levels (Public, Registered, Special, Super Users, Guest, custom). '
			. 'Returns id, title, ordering, and the rules JSON listing which user groups have access.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'ordering', 'rules']))
			->from($this->db->quoteName('#__viewlevels'))
			->order($this->db->quoteName('ordering') . ' ASC');
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		foreach ($rows as &$row) {
			$row['rules'] = $row['rules'] ? json_decode((string) $row['rules'], true) : null;
		}
		return ToolResult::json(['count' => count($rows), 'access_levels' => $rows]);
	}
}
