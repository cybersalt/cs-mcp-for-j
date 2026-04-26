<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetTagTool extends AbstractTool
{
	public function getName(): string { return 'get_tag'; }

	public function getDescription(): string { return 'Fetch a single tag by id.'; }

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
			->from($this->db->quoteName('#__tags'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		return $row ? ToolResult::json($row) : ToolResult::error('Tag ' . $id . ' not found.');
	}
}
