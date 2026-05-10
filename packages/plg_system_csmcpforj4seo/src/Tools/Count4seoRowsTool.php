<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class Count4seoRowsTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'count_4seo_rows'; }

	public function getDescription(): string
	{
		return 'Return the row count of every #__forseo_* table. Quick health snapshot for an '
			. 'agent — large counts in *_rules / *_redirects show how much customisation has '
			. 'been done; large counts in *_log show audit/scan activity.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$tables = $this->listForseoTableNames();
		$out    = [];
		$total  = 0;
		foreach ($tables as $name) {
			$full = $this->db->getPrefix() . $name;
			$count = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($full))->loadResult();
			$out[$name] = $count;
			$total += $count;
		}
		return ToolResult::json([
			'table_count' => count($tables),
			'total_rows'  => $total,
			'rows_per_table' => $out,
		]);
	}
}
