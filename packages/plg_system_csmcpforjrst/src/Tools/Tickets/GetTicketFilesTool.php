<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools\Tickets;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforjrst\Tools\RSTicketsProBootTrait;
use Joomla\CMS\User\User;

/**
 * Attachment metadata for files uploaded to a ticket. Returns the
 * filename + download count + which message the attachment was attached
 * to. Does NOT stream the file contents — actual files live under
 * components/com_rsticketspro/assets/files/ with hash-named filenames
 * and aren't useful to surface through MCP.
 */
final class GetTicketFilesTool extends AbstractTool
{
	use RSTicketsProBootTrait;

	public function getName(): string { return 'get_rst_ticket_files'; }

	public function getDescription(): string
	{
		return 'Return attachment metadata for one RSTicketsPro ticket (#__rsticketspro_ticket_'
			. 'files). Required: ticket_id. Each row: id, filename, downloads, ticket_message_id '
			. '(which message it was attached to). File contents are NOT returned — they live on '
			. 'disk under components/com_rsticketspro/assets/files/ with hashed names.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['ticket_id'],
			'properties' => [
				'ticket_id' => ['type' => 'integer'],
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

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'ticket_id', 'ticket_message_id', 'filename', 'downloads']))
			->from($this->db->quoteName($prefix . 'rsticketspro_ticket_files'))
			->where($this->db->quoteName('ticket_id') . ' = ' . $ticketId)
			->order($this->db->quoteName('id') . ' ASC');
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		foreach ($rows as &$r) {
			$r['id']                = (int) $r['id'];
			$r['ticket_id']         = (int) $r['ticket_id'];
			$r['ticket_message_id'] = (int) $r['ticket_message_id'];
			$r['downloads']         = (int) $r['downloads'];
		}
		unset($r);

		return ToolResult::json([
			'ok'        => true,
			'ticket_id' => $ticketId,
			'count'     => count($rows),
			'files'     => $rows,
		]);
	}
}
