<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * com_content category lister. Kept in the Articles namespace because article
 * authors are the primary callers; for full category CRUD across any
 * extension see the Categories\* tool set.
 */
final class ListCategoriesTool extends AbstractTool
{
	public function getName(): string { return 'list_categories'; }

	public function getDescription(): string
	{
		return 'List com_content categories so an article can be assigned to a valid catid. '
			. 'For categories of other extensions (com_users, com_banners, com_contact, etc.) '
			. 'use list_categories_in instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'properties' => [
				'parent_id' => ['type' => 'integer'],
				'language'  => ['type' => 'string'],
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
			->select($this->db->quoteName(['id', 'title', 'alias', 'parent_id', 'level', 'language', 'published']))
			->from($this->db->quoteName('#__categories'))
			->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
			->order($this->db->quoteName('lft') . ' ASC');

		if (isset($arguments['parent_id'])) {
			$query->where($this->db->quoteName('parent_id') . ' = ' . (int) $arguments['parent_id']);
		}
		if (!empty($arguments['language'])) {
			$query->where($this->db->quoteName('language') . ' = ' . $this->db->quote((string) $arguments['language']));
		}
		if (isset($arguments['published']) && in_array((int) $arguments['published'], [0, 1], true)) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}

		$limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'categories' => $rows]);
	}
}
