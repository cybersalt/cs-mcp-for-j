<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * System → Maintenance: Database as a read-only tool (GitHub #48).
 *
 * Every core upgrade and most extension upgrades end with "check the Database
 * screen and press Fix if anything is listed". After the nijf.ca J5 → J6
 * upgrade (29 Oct 2026) that was the one step left that needed the admin.
 * The report comes from Joomla's own com_installer Database model, so it
 * lists what that screen lists, per extension, core first.
 */
final class CheckDatabaseSchemaTool extends AbstractTool
{
	use DatabaseSchemaTrait;

	public function getName(): string { return 'check_database_schema'; }

	public function getDescription(): string
	{
		return 'Read-only. The same check as System → Maintenance: Database in the Joomla admin, run by '
			. 'Joomla\'s own code. For every extension with database update files (Joomla CMS first) it '
			. 'reports the schema version stored in #__schemas against the newest SQL update file, the '
			. 'version in the extension\'s manifest cache against its installed XML, and any table '
			. 'structure problems (missing tables, columns or indexes, wrong column types), with '
			. 'Joomla\'s own messages. verdict is "ok" or "problems_found". Run it after every Joomla '
			. 'or extension upgrade; if it finds problems, fix_database_schema does what the Fix button '
			. 'does. Set only_problems=true to leave out the extensions that are fine.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'only_problems' => [
					'type'        => 'boolean',
					'description' => 'Optional, default false. true lists only the extensions with problems.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		$this->requireInstallerManager($actor);

		$report   = $this->schemaReport();
		$problems = $this->problemTotal($report);
		$listed   = ($arguments['only_problems'] ?? false) === true
			? array_filter($report, static fn (array $e): bool => $e['problems_count'] > 0)
			: $report;

		return ToolResult::json([
			'verdict'             => $problems === 0 ? 'ok' : 'problems_found',
			'problems_total'      => $problems,
			'extensions_checked'  => \count($report),
			'extensions_with_problems' => array_values(array_map(
				static fn (array $e): string => $e['name'] . ' (id ' . $e['extension_id'] . ')',
				array_filter($report, static fn (array $e): bool => $e['problems_count'] > 0)
			)),
			'summary'             => $problems === 0
				? 'The database is up to date: System → Maintenance: Database lists no problems.'
				: $problems . ' problem(s) found. fix_database_schema with confirm=true fixes them, as the Fix button on System → Maintenance: Database does.',
			'extensions'          => array_values($listed),
		]);
	}
}
