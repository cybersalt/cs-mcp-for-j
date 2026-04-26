<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListTagsTool extends AbstractTool
{
	public function getName(): string { return 'list_tags'; }

	public function getDescription(): string
	{
		return 'List Joomla tags. Optional substring search; optional published filter.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'search'    => ['type' => 'string'],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'alias', 'parent_id', 'level', 'published', 'language', 'access']))
			->from($this->db->quoteName('#__tags'))
			->where($this->db->quoteName('id') . ' > 1') // skip ROOT
			->order($this->db->quoteName('lft') . ' ASC');

		if (!empty($arguments['search'])) {
			$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
			$query->where($this->db->quoteName('title') . ' LIKE ' . $this->db->quote($like, false));
		}
		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}

		$limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'tags' => $rows]);
	}
}
