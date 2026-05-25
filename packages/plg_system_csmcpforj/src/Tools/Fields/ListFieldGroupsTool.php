<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListFieldGroupsTool extends AbstractTool
{
	public function getName(): string { return 'list_field_groups'; }

	public function getDescription(): string
	{
		return 'List custom-field groups from #__fields_groups, optionally filtered by context '
			. '(e.g. "com_content.article", "com_users.user", "com_contact.contact") or state. '
			. 'Returns id, title, context, state, access, ordering, language, description. Groups '
			. 'are the tabs that custom fields appear under in the article (or other context) '
			. 'editor — assign a field to a group via update_custom_field(group_id=...) to make '
			. 'it appear under that tab.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'context' => ['type' => 'string'],
				'state'   => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'context', 'state', 'access', 'ordering', 'language', 'description', 'note']))
			->from($this->db->quoteName('#__fields_groups'))
			->order($this->db->quoteName('context') . ', ' . $this->db->quoteName('ordering'));

		if (!empty($arguments['context'])) {
			$query->where($this->db->quoteName('context') . ' = ' . $this->db->quote((string) $arguments['context']));
		}
		if (isset($arguments['state'])) {
			$query->where($this->db->quoteName('state') . ' = ' . (int) $arguments['state']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'groups' => $rows]);
	}
}
