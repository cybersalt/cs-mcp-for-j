<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Fields;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetCustomFieldTool extends AbstractTool
{
	public function getName(): string { return 'get_custom_field'; }

	public function getDescription(): string { return 'Fetch a single custom field by id, including its fieldparams.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__fields'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Custom field ' . $id . ' not found.');
		}
		$row['params']      = $row['params'] ? json_decode((string) $row['params'], true) : null;
		$row['fieldparams'] = $row['fieldparams'] ? json_decode((string) $row['fieldparams'], true) : null;
		return ToolResult::json($row);
	}
}
