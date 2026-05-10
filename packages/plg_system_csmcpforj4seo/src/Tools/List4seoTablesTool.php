<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class List4seoTablesTool extends AbstractTool
{
	use ForseoTableTrait;

	public function getName(): string { return 'list_4seo_tables'; }

	public function getDescription(): string
	{
		return 'List every database table belonging to 4SEO (#__forseo_*). Use this first '
			. 'when working with 4SEO — Weeblr ships no public API, so the agent needs to '
			. 'discover the schema before reading or writing.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$tables = $this->listForseoTableNames();
		return ToolResult::json([
			'count'  => count($tables),
			'prefix' => $this->db->getPrefix(),
			'tables' => $tables,
		]);
	}
}
