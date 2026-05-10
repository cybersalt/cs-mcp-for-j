<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Safe parameterised SELECT against any forseo_* table. Accepts a structured
 * `where` array of {column, op, value} clauses combined with AND. No raw SQL,
 * no JOINs, no subqueries. Operators restricted to a small allowlist.
 */
final class Query4seoTableTool extends AbstractTool
{
	use ForseoTableTrait;

	private const ALLOWED_OPS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'IS NULL', 'IS NOT NULL', 'IN'];

	public function getName(): string { return 'query_4seo_table'; }

	public function getDescription(): string
	{
		return 'Read rows from any #__forseo_* table with optional filters and column selection. '
			. 'Use describe_4seo_table first to learn the schema. WHERE conditions are passed as '
			. 'structured clauses {column, op, value} combined with AND — no raw SQL is accepted. '
			. 'Allowed operators: =, !=, <, <=, >, >=, LIKE, IS NULL, IS NOT NULL, IN.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table'],
			'properties' => [
				'table'   => ['type' => 'string', 'description' => 'Table name with or without forseo_ prefix.'],
				'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Columns to select. Default: all (SELECT *).'],
				'where'   => [
					'type'  => 'array',
					'items' => [
						'type' => 'object',
						'required' => ['column', 'op'],
						'properties' => [
							'column' => ['type' => 'string'],
							'op'     => ['type' => 'string', 'enum' => self::ALLOWED_OPS],
							'value'  => ['description' => 'Required for all operators except IS NULL / IS NOT NULL. For IN, pass an array.'],
						],
					],
				],
				'order_by' => ['type' => 'string', 'description' => 'Column to ORDER BY.'],
				'order_dir' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'description' => 'Default ASC.'],
				'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Default 100.'],
				'offset'  => ['type' => 'integer', 'minimum' => 0, 'description' => 'Default 0.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$name      = $this->resolveForseoTable($this->requireString($arguments, 'table'));
		$fullTable = $this->db->getPrefix() . $name;
		$schema    = array_column($this->tableColumns($name), 'Field');

		// WHERE-clause builder reused for both the page query and the COUNT(*)
		// total query so total reflects the same filter the page does. Returns
		// null on success or ['error' => msg] on validation failure.
		$applyWhere = function ($query) use ($arguments, $schema): ?array {
			if (empty($arguments['where']) || !is_array($arguments['where'])) {
				return null;
			}
			foreach ($arguments['where'] as $clause) {
				$col = (string) ($clause['column'] ?? '');
				$op  = strtoupper((string) ($clause['op'] ?? '='));
				if (!in_array($col, $schema, true)) {
					return ['error' => 'Unknown WHERE column: ' . $col];
				}
				if (!in_array($op, self::ALLOWED_OPS, true)) {
					return ['error' => 'Disallowed WHERE op: ' . $op];
				}
				$qcol = $this->db->quoteName($col);
				if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
					$query->where($qcol . ' ' . $op);
				} elseif ($op === 'IN') {
					$values = (array) ($clause['value'] ?? []);
					if ($values === []) {
						return ['error' => 'IN requires a non-empty value array.'];
					}
					$quoted = array_map(fn ($v) => $this->db->quote((string) $v), $values);
					$query->where($qcol . ' IN (' . implode(',', $quoted) . ')');
				} else {
					$value = $clause['value'] ?? null;
					if ($value === null) {
						return ['error' => 'Operator ' . $op . ' requires value.'];
					}
					$query->where($qcol . ' ' . $op . ' ' . $this->db->quote((string) $value));
				}
			}
			return null;
		};

		$query = $this->db->getQuery(true)->from($this->db->quoteName($fullTable));

		// Columns
		if (!empty($arguments['columns']) && is_array($arguments['columns'])) {
			$cols = [];
			foreach ($arguments['columns'] as $c) {
				$c = (string) $c;
				if (!in_array($c, $schema, true)) {
					return ToolResult::error('Unknown column: ' . $c);
				}
				$cols[] = $this->db->quoteName($c);
			}
			$query->select($cols);
		} else {
			$query->select('*');
		}

		$err = $applyWhere($query);
		if ($err !== null) {
			return ToolResult::error($err['error']);
		}

		// ORDER BY (must be a real column)
		if (!empty($arguments['order_by'])) {
			$ob = (string) $arguments['order_by'];
			if (!in_array($ob, $schema, true)) {
				return ToolResult::error('Unknown ORDER BY column: ' . $ob);
			}
			$dir = strtoupper((string) ($arguments['order_dir'] ?? 'ASC'));
			$dir = in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'ASC';
			$query->order($this->db->quoteName($ob) . ' ' . $dir);
		}

		$limit  = isset($arguments['limit']) ? max(1, min(1000, (int) $arguments['limit'])) : 100;
		$offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;
		$query->setLimit($limit, $offset);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		// Total across whole filtered set so the agent doesn't have to
		// poll-till-empty to know when pagination is done.
		$totalQuery = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($fullTable));
		$applyWhere($totalQuery); // already validated by the page query
		$total = (int) $this->db->setQuery($totalQuery)->loadResult();

		return ToolResult::json([
			'table'  => $name,
			'total'  => $total,
			'count'  => count($rows),
			'limit'  => $limit,
			'offset' => $offset,
			'rows'   => $rows,
		]);
	}
}
