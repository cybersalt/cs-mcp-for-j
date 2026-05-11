<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Meta;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Typed wrapper over #__forseo_custom_meta. Lists per-page meta overrides
 * with the bits an SEO agent cares about (which URL, what custom title,
 * what custom description, is the user-set override active) — without the
 * agent having to know about hash columns, source flags, the data JSON
 * envelope, etc.
 *
 * The generic `query_4seo_table` still works for ad-hoc reads of this
 * table; this tool is the convenience surface for the audit workflow.
 */
final class ListMetaOverridesTool extends AbstractTool
{
	public function getName(): string { return 'list_4seo_meta_overrides'; }

	public function getDescription(): string
	{
		return 'List 4SEO per-page meta overrides (#__forseo_custom_meta). Returns id, '
			. 'content_id, url, status flags, and decoded custom title/description so an '
			. 'audit query like "which articles have a custom SEO title set?" is one call. '
			. 'Filter by content_id substring, by has_custom_title=true (status_title=2), '
			. 'or by enabled state. Optional limit/offset for pagination.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'content_id_like' => ['type' => 'string', 'description' => 'Substring filter against content_id. E.g. "com_content" for articles only.'],
				'has_custom_title' => ['type' => 'boolean', 'description' => 'true = only rows where status_title=2 (custom user-set); false = only rows where it isn\'t.'],
				'has_custom_description' => ['type' => 'boolean'],
				'enabled' => ['type' => 'integer', 'enum' => [0, 1]],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
				'offset' => ['type' => 'integer', 'minimum' => 0],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$prefix    = $this->db->getPrefix();
		$fullTable = $prefix . 'forseo_custom_meta';

		$applyFilters = function ($query) use ($arguments): void {
			if (!empty($arguments['content_id_like'])) {
				$like = '%' . $this->db->escape((string) $arguments['content_id_like'], true) . '%';
				$query->where($this->db->quoteName('content_id') . ' LIKE ' . $this->db->quote($like, false));
			}
			if (isset($arguments['has_custom_title'])) {
				$op = $arguments['has_custom_title'] ? ' = 2' : ' != 2';
				$query->where($this->db->quoteName('status_title') . $op);
			}
			if (isset($arguments['has_custom_description'])) {
				$op = $arguments['has_custom_description'] ? ' = 2' : ' != 2';
				$query->where($this->db->quoteName('status_description') . $op);
			}
			if (isset($arguments['enabled'])) {
				$query->where($this->db->quoteName('enabled') . ' = ' . (int) $arguments['enabled']);
			}
		};

		// Page rows
		$page = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'content_id', 'url', 'data', 'status_title', 'status_description', 'enabled', 'crawled_at']))
			->from($this->db->quoteName($fullTable))
			->order($this->db->quoteName('id') . ' ASC');
		$applyFilters($page);

		$limit  = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 50;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$page->setLimit($limit, $offset);

		$rows = $this->db->setQuery($page)->loadAssocList() ?: [];

		// Decode the data JSON and surface the custom title/description fields
		foreach ($rows as &$row) {
			$decoded = json_decode((string) ($row['data'] ?? ''), true);
			if (is_array($decoded)) {
				$custom = is_array($decoded['custom'] ?? null) ? $decoded['custom'] : [];
				$row['custom_title']       = $custom['title'] ?? null;
				$row['custom_description'] = $custom['description'] ?? null;
				$row['custom_robots']      = $custom['robots'] ?? null;
				$row['custom_canonical']   = $custom['canonical'] ?? null;
			}
			unset($row['data']); // drop the huge raw blob from the response — agent rarely needs it
		}
		unset($row);

		// Total across the whole filtered set (pagination escape hatch)
		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($fullTable));
		$applyFilters($totalQuery);
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		return ToolResult::json([
			'total'  => $total,
			'count'  => count($rows),
			'limit'  => $limit,
			'offset' => $offset,
			'overrides' => $rows,
		]);
	}
}
