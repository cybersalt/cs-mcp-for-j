<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListArticlesTool extends AbstractTool
{
	public function getName(): string { return 'list_articles'; }

	public function getDescription(): string
	{
		return 'List articles with optional filters: search by title substring, by category, '
			. 'by author, by state, by language. Returns id, title, alias, catid, state, '
			. 'language, access, created/modified timestamps, hits.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'properties' => [
				'search'    => ['type' => 'string', 'description' => 'Substring of title (LIKE).'],
				'catid'     => ['type' => 'integer'],
				'author_id' => ['type' => 'integer', 'description' => 'created_by user id.'],
				'state'     => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'featured'  => ['type' => 'integer', 'enum' => [0, 1]],
				'language'  => ['type' => 'string'],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Default 50.'],
				'offset'    => ['type' => 'integer', 'minimum' => 0, 'description' => 'Default 0.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$applyFilters = function ($query) use ($arguments): void {
			if (!empty($arguments['search'])) {
				$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
				$query->where($this->db->quoteName('a.title') . ' LIKE ' . $this->db->quote($like, false));
			}
			if (isset($arguments['catid'])) {
				$query->where($this->db->quoteName('a.catid') . ' = ' . (int) $arguments['catid']);
			}
			if (isset($arguments['author_id'])) {
				$query->where($this->db->quoteName('a.created_by') . ' = ' . (int) $arguments['author_id']);
			}
			if (isset($arguments['state'])) {
				$query->where($this->db->quoteName('a.state') . ' = ' . (int) $arguments['state']);
			}
			if (isset($arguments['featured'])) {
				$query->where($this->db->quoteName('a.featured') . ' = ' . (int) $arguments['featured']);
			}
			if (!empty($arguments['language'])) {
				$query->where($this->db->quoteName('a.language') . ' = ' . $this->db->quote((string) $arguments['language']));
			}
		};

		// Page rows
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('a.id'), $this->db->quoteName('a.title'), $this->db->quoteName('a.alias'),
				$this->db->quoteName('a.catid'), $this->db->quoteName('c.title', 'category_title'),
				$this->db->quoteName('a.state'), $this->db->quoteName('a.featured'),
				$this->db->quoteName('a.language'), $this->db->quoteName('a.access'),
				$this->db->quoteName('a.created_by'), $this->db->quoteName('a.created'),
				$this->db->quoteName('a.modified'), $this->db->quoteName('a.hits'),
			])
			->from($this->db->quoteName('#__content', 'a'))
			->leftJoin(
				$this->db->quoteName('#__categories', 'c')
				. ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('a.catid')
			)
			->order($this->db->quoteName('a.id') . ' DESC');
		$applyFilters($query);

		$limit  = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 50;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$query->setLimit($limit, $offset);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Total across the whole filtered set (no pagination) so the agent
		// knows when to stop paginating without poll-till-empty.
		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName('#__content', 'a'));
		$applyFilters($totalQuery);
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		return ToolResult::json([
			'total'    => $total,
			'count'    => count($rows),
			'limit'    => $limit,
			'offset'   => $offset,
			'articles' => $rows,
		]);
	}
}
