<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * The audit log for one ticket — every status / dept / priority / staff
 * change. Useful for "what happened to this ticket over its lifetime?"
 *
 * Schema is intentionally lean (id, ticket_id, user_id, ip, date, type) —
 * 4SEO-style "what changed from-to" diffs aren't stored. The vault note's
 * RSTicketsProHelper::saveSystemMessage examples show the from/to data
 * is stored in #__rsticketspro_ticket_messages as a system message rather
 * than in ticket_history.
 */
final class GetTicketHistoryTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'get_rst_ticket_history'; }

	public function getDescription(): string
	{
		return 'Return the audit-log history for one RSTicketsPro ticket. Required: ticket_id. '
			. 'Each row: id, user_id (who did it), user_name, date, type (e.g. "status", '
			. '"department", "priority", "staff", "add", "delete"), ip. Ordered most-recent-first '
			. 'by default. Note: from/to values for status/dept/priority/staff changes are stored '
			. 'as system messages in get_rst_ticket_messages, not in this audit log.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ticket_id'],
			'properties' => [
				'ticket_id' => ['type' => 'integer'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'     => ['type' => 'integer'],
				'offset'    => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ticketId = $this->requirePositiveInt($arguments, 'ticket_id');
		if ($this->rstAdminBase() === null) {
			return $this->notInstalledError();
		}

		$prefix = $this->db->getPrefix();
		$dir = strtoupper((string) ($arguments['order_dir'] ?? 'DESC'));
		if (!in_array($dir, ['ASC', 'DESC'], true)) {
			$dir = 'DESC';
		}
		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 100)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('h.id'),
				$this->db->quoteName('h.ticket_id'),
				$this->db->quoteName('h.user_id'),
				$this->db->quoteName('u.name', 'user_name'),
				$this->db->quoteName('h.ip'),
				$this->db->quoteName('h.date'),
				$this->db->quoteName('h.type'),
			])
			->from($this->db->quoteName($prefix . 'rsticketspro_ticket_history', 'h'))
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'u') . ' ON u.id = h.user_id')
			->where($this->db->quoteName('h.ticket_id') . ' = ' . $ticketId)
			->order($this->db->quoteName('h.date') . ' ' . $dir)
			->order($this->db->quoteName('h.id') . ' ' . $dir);
		$this->db->setQuery($query, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			$r['id']        = (int) $r['id'];
			$r['ticket_id'] = (int) $r['ticket_id'];
			$r['user_id']   = (int) $r['user_id'];
		}
		unset($r);

		return ToolResult::json([
			'ok'        => true,
			'ticket_id' => $ticketId,
			'count'     => count($rows),
			'history'   => $rows,
		]);
	}
}
