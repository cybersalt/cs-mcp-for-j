<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListUserGroupsTool extends AbstractTool
{
	public function getName(): string { return 'list_user_groups'; }

	public function getDescription(): string
	{
		return 'List Joomla user groups (Public, Registered, Author, Editor, Manager, Administrator, Super Users, custom groups). '
			. 'Returns id, parent_id, lft, rgt, title.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'parent_id', 'lft', 'rgt', 'title']))
			->from($this->db->quoteName('#__usergroups'))
			->order($this->db->quoteName('lft') . ' ASC');
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'groups' => $rows]);
	}
}
