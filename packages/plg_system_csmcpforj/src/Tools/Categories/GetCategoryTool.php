<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetCategoryTool extends AbstractTool
{
	public function getName(): string { return 'get_category'; }

	public function getDescription(): string
	{
		return 'Fetch a single category by id, returning its full record (title, alias, '
			. 'extension, parent, level, language, access, description, params).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
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
			->from($this->db->quoteName('#__categories'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Category ' . $id . ' not found.');
		}
		return ToolResult::json($row);
	}
}
