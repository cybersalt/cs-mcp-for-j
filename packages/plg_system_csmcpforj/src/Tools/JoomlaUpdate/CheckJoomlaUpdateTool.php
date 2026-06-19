<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Updater\Updater;
use Joomla\CMS\Version;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Check whether a Joomla core update is available. Read-only — does not
 * download or apply anything. Refreshes the update site cache first (so the
 * answer reflects what's actually published, not what was cached up to 24h
 * ago) and then returns the current and available version, plus a
 * release-notes URL when one is known.
 *
 * Pair with joomla_update_healthcheck before deciding to apply.
 */
final class CheckJoomlaUpdateTool extends AbstractTool
{
	public function getName(): string { return 'check_joomla_update'; }

	public function getDescription(): string
	{
		return 'Check whether a Joomla core update is available. Forces a fresh '
			. 'fetch from the configured Joomla update server (bypassing the up-to-24h '
			. 'local cache), then returns: current Joomla version, latest available '
			. 'version, whether an update is available, the release notes URL when '
			. 'one is known, and the install URL of the package. Read-only — does '
			. 'NOT download or apply anything. Call joomla_update_healthcheck next '
			. 'before deciding to apply.';
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

		// Joomla's core update site is registered under the pseudo "files_joomla"
		// extension (extension_id always 700 in J5/J6). Find that extension's
		// update sites + force-refresh them.
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('file'))
			->where($db->quoteName('element') . ' = ' . $db->quote('joomla'));
		$extensionId = (int) $db->setQuery($query)->loadResult();

		if ($extensionId <= 0) {
			return ToolResult::error(
				'Could not find the Joomla core update extension row. This is unusual '
				. '— typically `files_joomla` (type=file, element=joomla) is always '
				. 'present. Check Extensions → Manage on this site.'
			);
		}

		// Force-poll with cacheTimeout=0 so we ignore Joomla's local cache.
		$updater = Updater::getInstance();
		try {
			$updater->findUpdates([$extensionId], 0, Updater::STABILITY_STABLE);
		} catch (\Throwable $e) {
			return ToolResult::error('Update check failed: ' . $e->getMessage());
		}

		// Read whatever Joomla found in #__updates for that extension.
		$query = $db->getQuery(true)
			->select($db->quoteName(['version', 'detailsurl', 'infourl', 'data']))
			->from($db->quoteName('#__updates'))
			->where($db->quoteName('extension_id') . ' = ' . $extensionId)
			->order($db->quoteName('version') . ' DESC');
		$rows = $db->setQuery($query)->loadAssocList() ?: [];

		$current   = (new Version())->getShortVersion();
		$latest    = $rows[0] ?? null;
		$hasUpdate = $latest !== null && version_compare((string) $latest['version'], $current, '>');

		return ToolResult::json([
			'current_version' => $current,
			'latest_version'  => $latest['version'] ?? $current,
			'update_available' => $hasUpdate,
			'release_notes_url' => (string) ($latest['infourl'] ?? ''),
			'details_url'      => (string) ($latest['detailsurl'] ?? ''),
		]);
	}
}
