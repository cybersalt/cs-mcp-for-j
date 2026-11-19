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
 * The Fix button on System → Maintenance: Database (GitHub #48).
 *
 * Calls the same com_installer Database model fix() the admin controller
 * calls, then does what that controller does afterwards (purges the update
 * cache, flushes the asset versions). For each extension fix() applies the
 * missing structure changes from its SQL update files, sets #__schemas to
 * the newest update file and the manifest cache version to the installed
 * XML. For Joomla CMS it also deletes the obsolete core files listed in
 * administrator/components/com_admin/script.php, as the button does.
 *
 * Super User only, and only with confirm=true: it changes table structure.
 */
final class FixDatabaseSchemaTool extends AbstractTool
{
	use DatabaseSchemaTrait;

	public function getName(): string { return 'fix_database_schema'; }

	public function getDescription(): string
	{
		return 'Fix what check_database_schema reports, exactly as the Fix button on System → Maintenance: '
			. 'Database does, using Joomla\'s own code: applies the missing table changes from the '
			. 'extension\'s SQL update files, sets the schema version and the manifest cache version, '
			. 'and for Joomla CMS also deletes the obsolete core files Joomla\'s own Fix deletes. Changes '
			. 'the database structure, so: Super User only, take a backup first, run check_database_schema '
			. 'first and tell the user what will be fixed, then call with confirm=true. extension_ids '
			. 'limits the fix to those extensions (ids from check_database_schema); leave it out to fix '
			. 'everything the check lists. Returns the check before and after for each extension fixed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Required. Must be true. Set it only after the user has agreed to the fix.',
				],
				'extension_ids' => [
					'type'        => 'array',
					'items'       => ['type' => 'integer'],
					'description' => 'Optional. Extension ids from check_database_schema to fix. Leave out to fix every extension with problems.',
				],
			],
			'required'             => ['confirm'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
			'title'           => 'Fix the database structure (System → Maintenance: Database)',
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);

		if (!$actor->authorise('core.admin')) {
			return ToolResult::error(
				'fix_database_schema requires Super User (core.admin): it changes the structure of database '
				. 'tables and, for Joomla CMS, deletes obsolete core files. check_database_schema shows the '
				. 'problems without changing anything.'
			);
		}

		if (($arguments['confirm'] ?? null) !== true) {
			return ToolResult::error(
				'Refused: fix_database_schema needs confirm=true. Run check_database_schema, tell the user '
				. 'which extensions will be fixed and that the table structure will change, make sure a '
				. 'backup exists, then call again with confirm=true.'
			);
		}

		$requested = $arguments['extension_ids'] ?? null;

		if ($requested !== null && (!\is_array($requested) || array_filter($requested, static fn ($id): bool => !\is_int($id) || $id <= 0))) {
			return ToolResult::error('extension_ids must be a list of positive extension ids from check_database_schema.');
		}

		$before = $this->schemaReport();

		if ($requested !== null && $requested !== []) {
			$unknown = array_diff(array_unique($requested), array_keys($before));

			if ($unknown !== []) {
				return ToolResult::error(
					'Nothing was fixed. Not on System → Maintenance: Database (no schema row or no SQL update '
					. 'files): extension id(s) ' . implode(', ', $unknown) . '. The ids it lists: '
					. implode(', ', array_keys($before)) . '.'
				);
			}

			$candidates = array_unique($requested);
		} else {
			$candidates = array_keys($before);
		}

		$targets   = array_values(array_filter($candidates, static fn (int $id): bool => $before[$id]['problems_count'] > 0));
		// Only worth saying for ids the caller picked; otherwise it is every clean extension.
		$untouched = $requested ? array_values(array_diff($candidates, $targets)) : [];

		if ($targets === []) {
			return ToolResult::json([
				'ok'      => true,
				'fixed'   => [],
				'summary' => 'Nothing to fix: ' . ($requested ? 'the extension(s) asked for have' : 'System → Maintenance: Database lists')
					. ' no problems. Nothing was changed.',
			]);
		}

		$this->databaseModel()->fix($targets);

		// What DatabaseController::fix() does after the model: stale update
		// info goes, asset versions refresh.
		$cleanup = [];

		try {
			$this->bootComponent('com_joomlaupdate')->getMVCFactory()
				->createModel('Update', 'Administrator', ['ignore_request' => true])
				->purge();
			$cleanup[] = 'update cache purged';
		} catch (\Throwable $e) {
			$cleanup[] = 'update cache not purged (' . get_class($e) . ')';
		}

		$app = $this->app();

		if (method_exists($app, 'flushAssets')) {
			try {
				$app->flushAssets();
				$cleanup[] = 'asset versions flushed';
			} catch (\Throwable $e) {
			}
		}

		$after    = $this->schemaReport();
		$fixed    = [];
		$leftOver = 0;

		foreach ($targets as $id) {
			$now       = $after[$id] ?? null;
			$remaining = $now === null ? 0 : $now['problems_count'];
			$leftOver += $remaining;

			$fixed[] = [
				'extension_id'    => $id,
				'name'            => $before[$id]['name'],
				'problems_before' => $before[$id]['problems'],
				'problems_after'  => $now['problems'] ?? [],
				'status'          => $remaining === 0 ? 'fixed' : 'problems_remain',
				'schema_before'   => $before[$id]['schema_in_database'],
				'schema_after'    => $now['schema_in_database'] ?? $before[$id]['schema_in_database'],
			];
		}

		$totalAfter = $this->problemTotal($after);

		return ToolResult::json([
			'ok'                   => $leftOver === 0,
			'verdict_after'        => $totalAfter === 0 ? 'ok' : 'problems_found',
			'problems_total_after' => $totalAfter,
			'fixed'                => $fixed,
			'not_needing_a_fix'    => array_map(static fn (int $id): string => $before[$id]['name'] . ' (id ' . $id . ')', $untouched),
			'cleanup'              => $cleanup,
			'summary'              => $leftOver === 0
				? 'Fixed ' . \count($targets) . ' extension(s); the check now finds no problems for them.'
				: 'Ran the fix for ' . \count($targets) . ' extension(s), but ' . $leftOver . ' problem(s) remain. '
					. 'Joomla\'s Fix can\'t repair everything (e.g. a column type it can\'t convert); see problems_after.',
		]);
	}
}
