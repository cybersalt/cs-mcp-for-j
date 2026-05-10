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
 */
final class ListArticlesWithSchemaTool extends AbstractTool
{
	public function getName(): string { return 'list_articles_with_schema'; }

	public function getDescription(): string
	{
		return 'List articles with their current Schema.org type assignment. Useful for '
			. 'auditing which articles already have structured data and which are missing it. '
			. 'schema_type is null for articles without a schemaorg row.';
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
		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('a.id'),
				$this->db->quoteName('a.title'),
				$this->db->quoteName('a.alias'),
				$this->db->quoteName('a.catid'),
				$this->db->quoteName('a.state'),
				$this->db->quoteName('s.schemaType', 'schema_type'),
				$this->db->quoteName('s.id', 'schema_row_id'),
			])
			->from($this->db->quoteName('#__content', 'a'))
			->leftJoin(
				$this->db->quoteName('#__schemaorg', 's')
				. ' ON ' . $this->db->quoteName('s.itemId') . ' = ' . $this->db->quoteName('a.id')
				. ' AND ' . $this->db->quoteName('s.context') . ' = ' . $this->db->quote('com_content.article')
			)
			->order($this->db->quoteName('a.id') . ' DESC');

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

		$limit  = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$query->setLimit($limit, $offset);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$summary = ['with_schema' => 0, 'without_schema' => 0, 'by_type' => []];
		foreach ($rows as $row) {
			if ($row['schema_type'] === null) {
				$summary['without_schema']++;
			} else {
				$summary['with_schema']++;
				$type = (string) $row['schema_type'];
				$summary['by_type'][$type] = ($summary['by_type'][$type] ?? 0) + 1;
			}
		}

		return ToolResult::json([
			'count'    => count($rows),
			'limit'    => $limit,
			'offset'   => $offset,
			'summary'  => $summary,
			'articles' => $rows,
		]);
	}
}
