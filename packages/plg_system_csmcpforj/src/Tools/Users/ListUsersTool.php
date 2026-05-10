<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListUsersTool extends AbstractTool
{
	public function getName(): string { return 'list_users'; }

	public function getDescription(): string
	{
		return 'List Joomla users. Optional substring search across name, username, email; '
			. 'optional block / activation / group filters. Returns id, name, username, '
			. 'email, registerDate, lastvisitDate, block, sendEmail.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'    => ['type' => 'string'],
				'group_id'  => ['type' => 'integer', 'description' => 'Only users in this user group.'],
				'block'     => ['type' => 'integer', 'enum' => [0, 1]],
				'activated' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 = users where activation = "" (already activated).'],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset'    => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$applyFilters = function ($query) use ($arguments): void {
			if (!empty($arguments['search'])) {
				$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
				$q = $this->db->quote($like, false);
				$query->where('(' . $this->db->quoteName('u.name') . ' LIKE ' . $q
					. ' OR ' . $this->db->quoteName('u.username') . ' LIKE ' . $q
					. ' OR ' . $this->db->quoteName('u.email') . ' LIKE ' . $q . ')');
			}
			if (isset($arguments['block'])) {
				$query->where($this->db->quoteName('u.block') . ' = ' . (int) $arguments['block']);
			}
			if (isset($arguments['activated'])) {
				$query->where($this->db->quoteName('u.activation') . ((int) $arguments['activated'] === 1 ? ' = ' : ' != ') . $this->db->quote(''));
			}
			if (isset($arguments['group_id'])) {
				$query->innerJoin($this->db->quoteName('#__user_usergroup_map', 'm') . ' ON ' . $this->db->quoteName('m.user_id') . ' = ' . $this->db->quoteName('u.id'));
				$query->where($this->db->quoteName('m.group_id') . ' = ' . (int) $arguments['group_id']);
			}
		};

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['u.id', 'u.name', 'u.username', 'u.email', 'u.registerDate', 'u.lastvisitDate', 'u.block', 'u.sendEmail', 'u.requireReset']))
			->from($this->db->quoteName('#__users', 'u'))
			->order($this->db->quoteName('u.id') . ' DESC');
		$applyFilters($query);

		$limit  = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 50;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$query->setLimit($limit, $offset);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Total across whole filtered set so the agent doesn't have to
		// poll-till-empty to know when pagination is done.
		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(DISTINCT ' . $this->db->quoteName('u.id') . ')')
			->from($this->db->quoteName('#__users', 'u'));
		$applyFilters($totalQuery);
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		return ToolResult::json([
			'total'  => $total,
			'count'  => count($rows),
			'limit'  => $limit,
			'offset' => $offset,
			'users'  => $rows,
		]);
	}
}
