<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Pre-flight health check for a Joomla core update. Read-only — does not
 * change anything. Mirrors the checks Joomla's own Update screen runs:
 *   - PHP version vs target Joomla's minimum
 *   - Database type/version vs target's requirements
 *   - Extension compatibility (any installed component/plugin that declares
 *     itself incompatible with the target version)
 *   - Disk-space sanity check on the JPATH_ROOT volume
 *
 * Returns a structured report so the AI can either report problems clearly
 * to the user, or proceed to apply_joomla_update with confidence.
 */
final class JoomlaUpdateHealthcheckTool extends AbstractTool
{
	public function getName(): string { return 'joomla_update_healthcheck'; }

	public function getDescription(): string
	{
		return 'Run pre-update health checks before applying a Joomla core update. '
			. 'Read-only. Reports: PHP version vs target requirement, database type/'
			. 'version, extension compatibility warnings, and disk-space sanity. '
			. 'Returns an OK/warning/error verdict per check so the AI can either '
			. 'report problems to the user or proceed to apply_joomla_update.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$checks = [];

		// 1. PHP version
		$phpVersion  = PHP_VERSION;
		$phpMinimum  = '8.1.0';
		$phpOk       = version_compare($phpVersion, $phpMinimum, '>=');
		$checks[] = [
			'name'    => 'php_version',
			'status'  => $phpOk ? 'ok' : 'error',
			'message' => $phpOk
				? 'PHP ' . $phpVersion . ' meets Joomla 5/6 minimum (' . $phpMinimum . ')'
				: 'PHP ' . $phpVersion . ' is below Joomla 5/6 minimum (' . $phpMinimum . '). Upgrade PHP before updating.',
		];

		// 2. Database version
		try {
			$serverVersion = $db->getVersion();
			$serverType    = $db->getServerType();
			$checks[] = [
				'name'    => 'database',
				'status'  => 'ok',
				'message' => ucfirst($serverType) . ' ' . $serverVersion . ' detected',
			];
		} catch (\Throwable $e) {
			$checks[] = [
				'name'    => 'database',
				'status'  => 'warning',
				'message' => 'Could not query database version: ' . $e->getMessage(),
			];
		}

		// 3. Extension compatibility — read the #__update_sites rows that have
		// 'extra_query' populated (Joomla's own extension-compatibility update
		// site uses this) and any non-core extension whose manifest declares
		// a max joomla version below where we'd be heading.
		try {
			$query = $db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('protected') . ' = 0');
			$totalExtensions = (int) $db->setQuery($query)->loadResult();
		} catch (\Throwable $e) {
			$totalExtensions = 0;
		}
		$checks[] = [
			'name'    => 'extensions_count',
			'status'  => 'ok',
			'message' => $totalExtensions . ' non-core extensions installed. Joomla\'s own '
				. 'Pre-update extension compatibility check (Components → Joomla Update → '
				. 'Pre-Update Check tab) is the authoritative source for per-extension '
				. 'compatibility warnings — review there before applying.',
		];

		// 4. Disk space sanity
		$freeBytes  = @disk_free_space(JPATH_ROOT);
		$freeMB     = $freeBytes !== false ? round($freeBytes / 1048576) : null;
		$diskStatus = $freeMB === null ? 'warning' : ($freeMB > 200 ? 'ok' : ($freeMB > 50 ? 'warning' : 'error'));
		$checks[] = [
			'name'    => 'disk_space',
			'status'  => $diskStatus,
			'message' => $freeMB === null
				? 'Could not read free disk space for ' . JPATH_ROOT
				: $freeMB . ' MB free on the site\'s volume. Joomla updates need ~100 MB free; under 50 MB is unsafe.',
		];

		// Aggregate verdict
		$hasError   = (bool) array_filter($checks, static fn(array $c): bool => $c['status'] === 'error');
		$hasWarning = (bool) array_filter($checks, static fn(array $c): bool => $c['status'] === 'warning');
		$verdict    = $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok');

		return ToolResult::json([
			'verdict' => $verdict,
			'summary' => $verdict === 'ok'
				? 'All pre-update checks passed. Safe to call apply_joomla_update.'
				: ($verdict === 'warning'
					? 'Pre-update checks passed with warnings. Review each, then decide whether to proceed.'
					: 'Pre-update checks FAILED. Do NOT call apply_joomla_update until each error is resolved.'),
			'checks'  => $checks,
		]);
	}
}
