<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class Describe4seoTableTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'describe_4seo_table'; }

	public function getDescription(): string
	{
		return 'Describe a 4SEO table: column names, types, nullability, defaults, primary key. '
			. 'Pass the table name with or without the "forseo_" prefix (e.g. "rules" or "forseo_rules"). '
			. 'Refuses any table that doesn\'t start with "forseo_".';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['table'],
			'properties' => [
				'table' => ['type' => 'string', 'description' => 'Table name with or without forseo_ prefix.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$name    = $this->resolveForseoTable($this->requireString($arguments, 'table'));
		$columns = $this->tableColumns($name);
		$pk      = $this->findPrimaryKey($name);

		return ToolResult::json([
			'table'         => $name,
			'full_table'    => $this->db->getPrefix() . $name,
			'primary_key'   => $pk,
			'column_count'  => count($columns),
			'columns'       => $columns,
		]);
	}
}
