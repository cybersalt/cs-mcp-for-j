<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class Insert4seoRowTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'insert_4seo_row'; }

	public function getDescription(): string
	{
		return 'Insert a single row into a #__forseo_* table. Supply a flat key-value object '
			. 'where each key is a real column name. Use describe_4seo_table first to learn '
			. 'the schema. Returns the inserted row id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table', 'values'],
			'properties' => [
				'table'  => ['type' => 'string'],
				'values' => ['type' => 'object', 'description' => 'Flat object {column: value, ...}.'],
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
		$values    = $arguments['values'] ?? null;

		if (!is_array($values) || $values === []) {
			return ToolResult::error('values must be a non-empty object.');
		}

		$cols = [];
		$vals = [];
		foreach ($values as $col => $value) {
			if (!in_array($col, $schema, true)) {
				return ToolResult::error('Unknown column: ' . $col);
			}
			$cols[] = $this->db->quoteName($col);
			if ($value === null) {
				$vals[] = 'NULL';
			} elseif (is_bool($value)) {
				$vals[] = $value ? '1' : '0';
			} elseif (is_int($value) || is_float($value)) {
				$vals[] = (string) $value;
			} elseif (is_array($value) || is_object($value)) {
				$vals[] = $this->db->quote(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			} else {
				$vals[] = $this->db->quote((string) $value);
			}
		}

		$query = $this->db->getQuery(true)
			->insert($this->db->quoteName($fullTable))
			->columns($cols)
			->values(implode(',', $vals));
		$this->db->setQuery($query)->execute();
		$id = $this->db->insertid();

		return ToolResult::json([
			'ok'        => true,
			'table'     => $name,
			'inserted_id' => $id,
		]);
	}
}
