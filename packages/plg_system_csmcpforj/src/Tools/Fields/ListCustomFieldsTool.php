<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListCustomFieldsTool extends AbstractTool
{
	public function getName(): string { return 'list_custom_fields'; }

	public function getDescription(): string
	{
		return 'List custom fields, optionally filtered by context (e.g. "com_content.article", "com_users.user", "com_contact.contact"). '
			. 'Returns id, title, name, type, context, group_id, state.';
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
			->select($this->db->quoteName(['id', 'title', 'name', 'label', 'type', 'context', 'group_id', 'required', 'state', 'access', 'language', 'description']))
			->from($this->db->quoteName('#__fields'))
			->order($this->db->quoteName('context') . ', ' . $this->db->quoteName('ordering'));

		if (!empty($arguments['context'])) {
			$query->where($this->db->quoteName('context') . ' = ' . $this->db->quote((string) $arguments['context']));
		}
		if (isset($arguments['state'])) {
			$query->where($this->db->quoteName('state') . ' = ' . (int) $arguments['state']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'fields' => $rows]);
	}
}
