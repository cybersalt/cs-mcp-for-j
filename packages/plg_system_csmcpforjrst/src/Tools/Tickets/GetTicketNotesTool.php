<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Staff-only internal notes attached to a ticket. These are never shown
 * to the customer (separate table from ticket_messages).
 */
final class GetTicketNotesTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'get_rst_ticket_notes'; }

	public function getDescription(): string
	{
		return 'Return staff-only internal notes on one RSTicketsPro ticket (#__rsticketspro_'
			. 'ticket_notes). Required: ticket_id. Notes are invisible to the customer. Use '
			. 'add_rst_ticket_note to add one.';
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
		$dir = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));
		if (!in_array($dir, ['ASC', 'DESC'], true)) {
			$dir = 'ASC';
		}
		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 200)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('n.id'),
				$this->db->quoteName('n.ticket_id'),
				$this->db->quoteName('n.user_id'),
				$this->db->quoteName('u.name', 'user_name'),
				$this->db->quoteName('n.text'),
				$this->db->quoteName('n.date'),
			])
			->from($this->db->quoteName($prefix . 'rsticketspro_ticket_notes', 'n'))
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'u') . ' ON u.id = n.user_id')
			->where($this->db->quoteName('n.ticket_id') . ' = ' . $ticketId)
			->order($this->db->quoteName('n.date') . ' ' . $dir)
			->order($this->db->quoteName('n.id') . ' ' . $dir);
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
			'notes'     => $rows,
		]);
	}
}
