<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class Delete4seoRowTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'delete_4seo_row'; }

	public function getDescription(): string
	{
		return 'Delete a single row from a #__forseo_* table by primary key. Refuses if more '
			. 'than one row would match. NOTE: there is no trash for forseo_* tables — this is '
			. 'an immediate, permanent delete.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table', 'pk_value'],
			'properties' => [
				'table'     => ['type' => 'string'],
				'pk_column' => ['type' => 'string'],
				'pk_value'  => ['description' => 'Primary key value of the row to delete.'],
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

		$preflight = $this->db->getQuery(true)
			->select('COUNT(*)')
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName($pkColumn) . ' = ' . $this->db->quote((string) $pkValue));
		$matched = (int) $this->db->setQuery($preflight)->loadResult();
		if ($matched === 0) {
			return ToolResult::error('No row matches.');
		}
		if ($matched > 1) {
			return ToolResult::error('Refusing to delete — WHERE would match ' . $matched . ' rows.');
		}

		$query = $this->db->getQuery(true)
			->delete($this->db->quoteName($fullTable))
			->where($this->db->quoteName($pkColumn) . ' = ' . $this->db->quote((string) $pkValue));
		$this->db->setQuery($query)->execute();

		return ToolResult::json([
			'ok'        => true,
			'table'     => $name,
			'pk_column' => $pkColumn,
			'pk_value'  => $pkValue,
			'deleted'   => true,
		]);
	}
}
