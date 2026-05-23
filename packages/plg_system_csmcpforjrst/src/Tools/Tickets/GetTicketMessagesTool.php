<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * The conversation thread for one ticket. Returns one row per message,
 * oldest-first by default (chronological reading order).
 *
 * IMPORTANT field semantic — `submitted_by_staff` is NOT a boolean. Per
 * the vault note: it's the user_id of the most-recent staff member to
 * touch the ticket, or 0 if a customer was the latest submitter. Don't
 * filter on it. To tell who sent each message, compare user_id to the
 * list_rst_staff results (staff_user_ids).
 */
final class GetTicketMessagesTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'get_rst_ticket_messages'; }

	public function getDescription(): string
	{
		return 'Return the conversation thread for one RSTicketsPro ticket — one row per message '
			. '(customer or staff). Required: ticket_id. Optional: order_dir (default ASC = '
			. 'oldest-first, chronological), limit (default 200), offset, include_system_messages '
			. '(default true). Each row includes id, user_id, user_name, user_email, is_staff '
			. '(resolved against the RSTicketsPro staff list), message, html (1 if rich HTML, 0 '
			. 'if plaintext), date. SYSTEM MESSAGES (status/dept/priority/staff changes — '
			. 'user_id=-1) are decoded from RST\'s serialized PHP into a system_event {type, '
			. 'from, to, user_id} object and the message body is replaced with a human-readable '
			. 'summary. Pass include_system_messages=false to hide them and see only the actual '
			. 'conversation.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ticket_id'],
			'properties' => [
				'ticket_id'               => ['type' => 'integer'],
				'order_dir'               => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
				'limit'                   => ['type' => 'integer'],
				'offset'                  => ['type' => 'integer'],
				'include_system_messages' => ['type' => 'boolean', 'description' => 'Default true. Set false to filter out the synthetic status/dept/priority/staff-change marker rows (user_id=-1) and return only actual customer/staff conversation messages.'],
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
		$dir    = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));
		if (!in_array($dir, ['ASC', 'DESC'], true)) {
			$dir = 'ASC';
		}
		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 200)));
		$offset = max(0, (int) ($arguments['offset'] ?? 0));
		$includeSystem = !array_key_exists('include_system_messages', $arguments)
			|| (bool) $arguments['include_system_messages'];

		// Build the staff-user-id set so we can flag each message's author.
		$staffIds = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('user_id'))
				->from($this->db->quoteName($prefix . 'rsticketspro_staff'))
		)->loadColumn() ?: [];
		$staffIds = array_map('intval', $staffIds);

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('m.id'),
				$this->db->quoteName('m.ticket_id'),
				$this->db->quoteName('m.user_id'),
				$this->db->quoteName('u.name', 'user_name'),
				$this->db->quoteName('u.email', 'user_email'),
				$this->db->quoteName('m.message'),
				$this->db->quoteName('m.html'),
				$this->db->quoteName('m.date'),
				$this->db->quoteName('m.submitted_by_staff'),
			])
			->from($this->db->quoteName($prefix . 'rsticketspro_ticket_messages', 'm'))
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'u') . ' ON u.id = m.user_id')
			->where($this->db->quoteName('m.ticket_id') . ' = ' . $ticketId)
			->order($this->db->quoteName('m.date') . ' ' . $dir)
			->order($this->db->quoteName('m.id') . ' ' . $dir);
		$this->db->setQuery($query, $offset, $limit);
		$rows = $this->db->loadAssocList() ?: [];

		$filtered = [];
		foreach ($rows as $r) {
			$r['id']                 = (int) $r['id'];
			$r['ticket_id']          = (int) $r['ticket_id'];
			$r['user_id']            = (int) $r['user_id'];
			$r['html']               = (int) $r['html'];
			$r['submitted_by_staff'] = (int) $r['submitted_by_staff'];
			$r['is_staff']           = in_array($r['user_id'], $staffIds, true);
			$r['is_system']          = $r['user_id'] === -1;

			// Decode RST's system-message blobs. saveSystemMessage() inserts these
			// as user_id=-1, html=0, message=serialize([type=>..., from=>..., to=>...,
			// user_id=>...]). We unserialize with allowed_classes=false so an attacker-
			// crafted serialized object (gadget chain) can't instantiate anything —
			// RST only ever serializes a flat associative array of scalars here, so
			// the restriction is safe.
			if ($r['is_system']) {
				if (!$includeSystem) {
					continue;
				}
				$decoded = @unserialize((string) $r['message'], ['allowed_classes' => false]);
				if (is_array($decoded) && isset($decoded['type'])) {
					$r['system_event'] = [
						'type'    => (string) $decoded['type'],
						'from'    => $decoded['from'] ?? null,
						'to'      => $decoded['to'] ?? null,
						'user_id' => isset($decoded['user_id']) ? (int) $decoded['user_id'] : null,
					];
					$r['message'] = sprintf(
						'[system: %s changed from %s to %s by user %s]',
						$decoded['type'],
						$decoded['from'] ?? '?',
						$decoded['to'] ?? '?',
						$decoded['user_id'] ?? '?'
					);
				}
			}

			$filtered[] = $r;
		}
		$rows = $filtered;

		return ToolResult::json([
			'ok'        => true,
			'ticket_id' => $ticketId,
			'count'     => count($rows),
			'limit'     => $limit,
			'offset'    => $offset,
			'messages'  => $rows,
		]);
	}
}
