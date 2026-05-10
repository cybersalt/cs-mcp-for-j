<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Lists articles with their current Schema.org type assignment by left-joining
 * #__content against #__schemaorg. Articles with no schema row are returned
 * with schema_type=null, so the agent can spot which articles still need
 * structured data attached.
 *
 * Summary block reports across the WHOLE filtered set (not just the current
 * page), and the response includes a `total` so the agent knows when it has
 * paginated to the end without poll-till-empty.
 */
final class ListArticlesWithSchemaTool extends AbstractTool
{
	public function getName(): string { return 'list_articles_with_schema'; }

	public function getDescription(): string
	{
		return 'List articles with their current Schema.org type assignment. Useful for '
			. 'auditing which articles already have structured data and which are missing it. '
			. 'schema_type is null for articles without a schemaorg row. The summary block '
			. 'reflects the entire filtered set, not just the current page; `total` tells you '
			. 'how many rows match the filter overall.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'has_schema' => ['type' => 'boolean', 'description' => 'true = only articles WITH schema, false = only articles WITHOUT schema. Omit for both.'],
				'schema_type' => ['type' => 'string', 'description' => 'Filter to a specific type, e.g. "Article", "Custom", "BlogPosting".'],
				'catid'  => ['type' => 'integer'],
				'state'  => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'search' => ['type' => 'string', 'description' => 'Substring of article title.'],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset' => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$baseQuery = $this->db->getQuery(true)
			->from($this->db->quoteName('#__content', 'a'))
			->leftJoin(
				$this->db->quoteName('#__schemaorg', 's')
				. ' ON ' . $this->db->quoteName('s.itemId') . ' = ' . $this->db->quoteName('a.id')
				. ' AND ' . $this->db->quoteName('s.context') . ' = ' . $this->db->quote('com_content.article')
			);

		$applyFilters = function ($query) use ($arguments): void {
			if (isset($arguments['has_schema'])) {
				if ($arguments['has_schema']) {
					$query->where($this->db->quoteName('s.id') . ' IS NOT NULL');
				} else {
					$query->where($this->db->quoteName('s.id') . ' IS NULL');
				}
			}
			if (!empty($arguments['schema_type'])) {
				$query->where($this->db->quoteName('s.schemaType') . ' = ' . $this->db->quote((string) $arguments['schema_type']));
			}
			if (isset($arguments['catid'])) {
				$query->where($this->db->quoteName('a.catid') . ' = ' . (int) $arguments['catid']);
			}
			if (isset($arguments['state'])) {
				$query->where($this->db->quoteName('a.state') . ' = ' . (int) $arguments['state']);
			}
			if (!empty($arguments['search'])) {
				$like = '%' . $this->db->escape((string) $arguments['search'], true) . '%';
				$query->where($this->db->quoteName('a.title') . ' LIKE ' . $this->db->quote($like, false));
			}
		};

		// Page query — what the agent gets back as `articles`.
		$pageQuery = clone $baseQuery;
		$pageQuery->select([
			$this->db->quoteName('a.id'),
			$this->db->quoteName('a.title'),
			$this->db->quoteName('a.alias'),
			$this->db->quoteName('a.catid'),
			$this->db->quoteName('a.state'),
			$this->db->quoteName('s.schemaType', 'schema_type'),
			$this->db->quoteName('s.id', 'schema_row_id'),
		])->order($this->db->quoteName('a.id') . ' DESC');
		$applyFilters($pageQuery);

		$limit  = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$pageQuery->setLimit($limit, $offset);

		$rows = $this->db->setQuery($pageQuery)->loadAssocList() ?: [];

		// Total count across the WHOLE filtered set (not just the page).
		$totalQuery = clone $baseQuery;
		$totalQuery->select('COUNT(*)');
		$applyFilters($totalQuery);
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		// Summary across the filtered set, also not page-bound. Two more
		// scalar queries; cheap and the answer means something this time.
		$withQuery = clone $baseQuery;
		$withQuery->select('COUNT(*)');
		$applyFiltersWith = $applyFilters;
		$applyFiltersWith($withQuery);
		$withQuery->where($this->db->quoteName('s.id') . ' IS NOT NULL');
		$withSchema = (int) $this->db->setQuery($withQuery)->loadResult();

		$withoutSchema = $total - $withSchema;

		// by_type breakdown across the filtered set.
		$typeQuery = clone $baseQuery;
		$typeQuery->select([
			$this->db->quoteName('s.schemaType', 'schema_type'),
			'COUNT(*) AS n',
		]);
		$applyFilters($typeQuery);
		$typeQuery->where($this->db->quoteName('s.id') . ' IS NOT NULL')
			->group($this->db->quoteName('s.schemaType'));
		$typeRows = $this->db->setQuery($typeQuery)->loadAssocList() ?: [];
		$byType   = [];
		foreach ($typeRows as $row) {
			$byType[(string) $row['schema_type']] = (int) $row['n'];
		}

		return ToolResult::json([
			'total'    => $total,
			'count'    => count($rows),
			'limit'    => $limit,
			'offset'   => $offset,
			'summary'  => [
				'with_schema'    => $withSchema,
				'without_schema' => $withoutSchema,
				'by_type'        => $byType,
			],
			'articles' => $rows,
		]);
	}
}
