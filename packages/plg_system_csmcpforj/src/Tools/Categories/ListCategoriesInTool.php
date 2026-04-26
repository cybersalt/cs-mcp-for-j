<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Generic category lister — works for any Joomla extension that uses
 * com_categories. Examples: com_content (articles), com_users, com_banners,
 * com_contact, com_newsfeeds.
 */
final class ListCategoriesInTool extends AbstractTool
{
	public function getName(): string { return 'list_categories_in'; }

	public function getDescription(): string
	{
		return 'List categories belonging to a specific Joomla extension. Pass the extension '
			. 'context (e.g. "com_content", "com_users", "com_banners", "com_contact").';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['extension'],
			'properties' => [
				'extension' => ['type' => 'string', 'description' => 'e.g. "com_content".'],
				'parent_id' => ['type' => 'integer'],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'language'  => ['type' => 'string'],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ext = $this->requireString($arguments, 'extension');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'alias', 'parent_id', 'level', 'language', 'published', 'extension']))
			->from($this->db->quoteName('#__categories'))
			->where($this->db->quoteName('extension') . ' = ' . $this->db->quote($ext))
			->order($this->db->quoteName('lft') . ' ASC');

		if (isset($arguments['parent_id'])) {
			$query->where($this->db->quoteName('parent_id') . ' = ' . (int) $arguments['parent_id']);
		}
		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}
		if (!empty($arguments['language'])) {
			$query->where($this->db->quoteName('language') . ' = ' . $this->db->quote((string) $arguments['language']));
		}

		$limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['extension' => $ext, 'count' => count($rows), 'categories' => $rows]);
	}
}
