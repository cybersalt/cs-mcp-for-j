<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads 4SEO's actual config from #__forseo_config (the table where 4SEO
 * stores most of its settings). The Joomla #__extensions row's params field
 * holds almost nothing useful — get_4seo_component_params returns that, this
 * tool returns the data the user actually expects when they say "4SEO
 * settings".
 *
 * Schema-agnostic: returns every column from every row, so it survives
 * 4SEO version changes that add or rename columns. When the agent needs a
 * specific subset, follow up with query_4seo_table for a filtered read.
 */
final class Get4seoConfigTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'get_4seo_config'; }

	public function getDescription(): string
	{
		return 'Read 4SEO\'s actual configuration from #__forseo_config (which is where the '
			. 'real settings live — site-wide schema templates, default tags, scan rules). '
			. 'For the trivial Joomla extensions row use get_4seo_component_params instead. '
			. 'Returns every row and every column verbatim so it survives 4SEO version changes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Default 200.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$tables = $this->listForseoTableNames();
		if (!in_array('forseo_config', $tables, true)) {
			return ToolResult::error(
				'No #__forseo_config table found. 4SEO may not be installed, or its config '
				. 'lives under a different table name on this version. Run list_4seo_tables '
				. 'to see what is there.'
			);
		}

		$fullTable = $this->db->getPrefix() . 'forseo_config';
		$columns   = $this->tableColumns('forseo_config');
		$pk        = $this->findPrimaryKey('forseo_config');

		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($fullTable));
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		$limit = isset($arguments['limit']) ? max(1, min(1000, (int) $arguments['limit'])) : 200;

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($fullTable))
			->setLimit($limit);
		if ($pk !== null) {
			$query->order($this->db->quoteName($pk) . ' ASC');
		}
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Best-effort JSON decode of any column whose value parses as a JSON
		// object/array. 4SEO stores most non-trivial settings as JSON blobs
		// (rules, templates, schema overrides). Returning them parsed saves
		// the agent a round-trip — and we keep the raw alongside in case it
		// needs the original bytes.
		foreach ($rows as &$row) {
			foreach ($row as $col => $value) {
				if (is_string($value) && $value !== '' && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
					$decoded = json_decode($value, true);
					if (json_last_error() === JSON_ERROR_NONE) {
						$row[$col . '__parsed'] = $decoded;
					}
				}
			}
		}

		return ToolResult::json([
			'table'        => 'forseo_config',
			'total'        => $total,
			'count'        => count($rows),
			'limit'        => $limit,
			'primary_key'  => $pk,
			'column_names' => array_column($columns, 'Field'),
			'rows'         => $rows,
		]);
	}
}
