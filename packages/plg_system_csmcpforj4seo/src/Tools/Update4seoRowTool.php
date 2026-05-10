<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Updates one row in a forseo_* table, identified by primary key. To update
 * by a non-PK identifier (e.g. a unique slug column), pass pk_column. Affects
 * a single row at a time deliberately — agents shouldn't be able to write
 * across many rows without explicit per-row reasoning.
 */
final class Update4seoRowTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'update_4seo_row'; }

	public function getDescription(): string
	{
		return 'Update a single row in a #__forseo_* table. Required: table, pk_value (and pk_column '
			. 'if the primary key isn\'t auto-detected), set (object of column → value). Refuses to '
			. 'run if the WHERE would touch more than one row.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table', 'pk_value', 'set'],
			'properties' => [
				'table'     => ['type' => 'string'],
				'pk_column' => ['type' => 'string', 'description' => 'Override auto-detected primary key.'],
				'pk_value'  => ['description' => 'Value of the primary key for the row to update.'],
				'set'       => ['type' => 'object', 'description' => 'Flat object {column: new_value, ...}.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$name      = $this->resolveForseoTable($this->requireString($arguments, 'table'));
		$fullTable = $this->db->getPrefix() . $name;
		$schema    = array_column($this->tableColumns($name), 'Field');

		$pkColumn = (string) ($arguments['pk_column'] ?? '');
		if ($pkColumn === '') {
			$pkColumn = $this->findPrimaryKey($name) ?? '';
		}
		if ($pkColumn === '' || !in_array($pkColumn, $schema, true)) {
			return ToolResult::error('Could not resolve primary key column. Pass pk_column explicitly.');
		}

		$pkValue = $arguments['pk_value'] ?? null;
		if ($pkValue === null || $pkValue === '') {
			return ToolResult::error('pk_value is required.');
		}

		$set = $arguments['set'] ?? null;
		if (!is_array($set) || $set === []) {
			return ToolResult::error('set must be a non-empty object.');
		}

		// Pre-flight: verify the WHERE matches exactly one row
		$preflight = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName($pkColumn) . ' = ' . $this->db->quote((string) $pkValue));
		$matched = (int) $this->db->setQuery($preflight)->loadResult();

		if ($matched === 0) {
			return ToolResult::error('No row matches ' . $pkColumn . ' = ' . $pkValue);
		}
		if ($matched > 1) {
			return ToolResult::error('Refusing to update — WHERE would match ' . $matched . ' rows.');
		}

		$query = $this->db->getQuery(true)
			->update($this->db->quoteName($fullTable))
			->where($this->db->quoteName($pkColumn) . ' = ' . $this->db->quote((string) $pkValue));

		foreach ($set as $col => $value) {
			if (!in_array($col, $schema, true)) {
				return ToolResult::error('Unknown column in set: ' . $col);
			}
			if ($value === null) {
				$query->set($this->db->quoteName($col) . ' = NULL');
			} elseif (is_bool($value)) {
				$query->set($this->db->quoteName($col) . ' = ' . ($value ? '1' : '0'));
			} elseif (is_int($value) || is_float($value)) {
				$query->set($this->db->quoteName($col) . ' = ' . $value);
			} elseif (is_array($value) || is_object($value)) {
				$query->set(
					$this->db->quoteName($col) . ' = '
					. $this->db->quote(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
				);
			} else {
				$query->set($this->db->quoteName($col) . ' = ' . $this->db->quote((string) $value));
			}
		}

		$this->db->setQuery($query)->execute();

		return ToolResult::json([
			'ok'             => true,
			'table'          => $name,
			'pk_column'      => $pkColumn,
			'pk_value'       => $pkValue,
			'fields_changed' => array_keys($set),
		]);
	}
}
