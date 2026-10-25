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
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\Version;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

/**
 * Apply the available Joomla core update: download the package, extract it
 * over the site, run the upgrade. Destructive and effectively irreversible
 * without restoring from a backup. ALWAYS require an explicit `confirm=true`
 * flag from the caller — the AI can't trip into this tool through an
 * ambiguous chain.
 *
 * The steps are the ones Joomla's own CLI updater (`php cli/joomla.php
 * core:update`, Joomla\CMS\Console\UpdateCoreCommand) runs server-side: the
 * same pre-checks (database structure, Backward Compatibility plugins on a
 * major upgrade), download, unpack, copy over the site root, finaliseUpgrade(),
 * cleanup. The web UI does the extract step in the browser (extract.php over
 * AJAX), which an API request can't do — versions up to 2.9.4 skipped it and
 * reported success without installing anything.
 *
 * It takes two calls. The first copies the files; the second, in a new
 * request, runs finaliseUpgrade(). Doing both in one request mixes the old
 * version's already-loaded classes with the new files and broke a 5 → 6
 * upgrade halfway in testing. Joomla's CLI updater has the same flaw; the
 * web updater finishes in a separate request.
 *
 * Success is only reported after the new version is read back from disk
 * (libraries/src/Version.php) and from the database (the core extension's
 * manifest cache). The PHP process that ran the update still has the old
 * Version class loaded, so JVERSION can't be used for that.
 */
final class ApplyJoomlaUpdateTool extends AbstractTool
{
	public function getName(): string { return 'apply_joomla_update'; }

	public function getDescription(): string
	{
		return 'Apply the currently-available Joomla core update: download the package, '
			. 'extract it over the site and run the upgrade, the same steps as Joomla\'s CLI '
			. 'updater. DESTRUCTIVE and effectively irreversible without restoring from a '
			. 'backup — always make a fresh backup BEFORE calling this tool. Requires explicit '
			. 'confirm=true; without that flag the tool refuses, so the AI cannot trip into this '
			. 'through an ambiguous chain. Always call check_joomla_update and '
			. 'joomla_update_healthcheck first, and pass the version you expect as '
			. 'target_version. Refuses, like Joomla does, when the database structure has errors '
			. 'or when a major upgrade\'s Backward Compatibility plugin settings are not met. '
			. 'TWO CALLS: the first downloads and copies the new files and returns done=false, '
			. 'stage=files_copied; call it again straight away (same arguments) to finish the '
			. 'upgrade in a fresh request, as Joomla\'s web updater does. A call made while the '
			. 'files on disk are newer than the database always finishes that pending upgrade. '
			. 'Only the second call reports ok/done=true, after the new version is confirmed on '
			. 'disk and in the database. '
			. 'For Joomla 5 → 6 major upgrades or sites with extension compat warnings, prefer '
			. 'the web UI.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'confirm' => [
					'type'        => 'boolean',
					'description' => 'Required. Must be explicitly true. The AI must surface to the user that this is irreversible and a backup exists, then set confirm=true based on the user\'s authorisation.',
				],
				'target_version' => [
					'type'        => 'string',
					'description' => 'Optional but recommended: the version check_joomla_update offered, e.g. "5.4.11". The tool refuses if Joomla would install a different version.',
				],
			],
			'required'             => ['confirm'],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	/**
	 * Force destructiveHint regardless of name auto-classification — apply_*
	 * sounds idempotent-ish to the naming heuristic but the consequences here
	 * are deletions of every prior core file and a rewrite of the database.
	 */
	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
			'title'           => 'Apply Joomla core update (irreversible)',
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$actor->authorise('core.admin')) {
			return ToolResult::error(
				'apply_joomla_update requires Super User (core.admin). The csmcpforj.write grant alone '
				. 'is not sufficient because it replaces Joomla\'s core files. Joomla\'s own Update screen is limited to Super Users for the same reason.'
			);
		}

		if (($arguments['confirm'] ?? null) !== true) {
			return ToolResult::error(
				'Refused: apply_joomla_update requires explicit confirm=true. This is '
				. 'a destructive irreversible operation. Surface the implications to '
				. 'the user (current version, target version, the need for a backup), '
				. 'wait for their explicit go-ahead, then re-call with confirm=true.'
			);
		}

		$fromVersion   = \JVERSION;
		$targetVersion = trim((string) ($arguments['target_version'] ?? ''));

		Factory::getLanguage()->load('com_joomlaupdate', JPATH_ADMINISTRATOR);
		Factory::getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

		// Second call: the new files are on disk but the database still has the
		// old version, so finish the upgrade. Also the way out for a site whose
		// update stopped between the two steps.
		$onDisk = $this->versionOnDisk();
		$inDb   = $this->versionInDatabase();

		if ($onDisk !== null && $inDb !== null && version_compare($onDisk, $inDb, '>')) {
			return $this->finish($onDisk, $inDb, $targetVersion);
		}

		$model = $this->getModel('com_joomlaupdate', 'Update');
		$info  = $model->getUpdateInformation();

		if (empty($info['hasUpdate']) || empty($info['object'])) {
			return ToolResult::error(
				'No Joomla update is available to install (installed: ' . $fromVersion . ', latest known: '
				. ($info['latest'] ?? $fromVersion) . '). Call check_joomla_update first so Joomla\'s update '
				. 'poller sees the latest version.'
			);
		}

		$latest = (string) $info['latest'];

		if ($targetVersion !== '' && version_compare($targetVersion, $latest, '!=')) {
			return ToolResult::error(
				'Refused: target_version is ' . $targetVersion . ' but Joomla would install ' . $latest
				. '. Call check_joomla_update again and confirm the version with the user.'
			);
		}

		// Same pre-checks as Joomla's CLI updater.
		$schemaErrors = $this->schemaErrorCount();

		if ($schemaErrors > 0) {
			return ToolResult::error(
				'Refused: Joomla\'s database structure is not up to date (System → Maintenance: Database '
				. 'lists ' . $schemaErrors . ' problem(s) for Joomla CMS). Fix them there first; '
				. 'Joomla refuses to update in this state too.'
			);
		}

		if ((int) explode('.', $latest)[0] > Version::MAJOR_VERSION) {
			$problems = $this->majorUpgradeProblems();

			if ($problems !== []) {
				return ToolResult::error(
					'Refused: Joomla ' . $latest . ' is a major upgrade from ' . $fromVersion . ' and its '
					. 'pre-conditions are not met: ' . implode(' ', $problems)
					. ' Joomla\'s own updater refuses for the same reason. Change the plugin(s) in '
					. 'System → Plugins (or with set_extension_enabled), then try again.'
				);
			}
		}

		// Updating replaces thousands of files; don't let a timeout or a client
		// disconnect stop it halfway through.
		@set_time_limit(0);
		@ignore_user_abort(true);

		$app      = Factory::getApplication();
		$tmpPath  = (string) $app->get('tmp_path');
		$package  = null;
		$basename = '';

		try {
			$downloaded = $model->download();
			$basename   = \is_array($downloaded) ? (string) ($downloaded['basename'] ?? '') : '';

			if ($basename === '') {
				return ToolResult::error(
					'Downloading the Joomla ' . $latest . ' package failed (from every download mirror). '
					. 'Nothing was changed on the site.'
				);
			}

			if (($downloaded['check'] ?? null) === false) {
				$this->deleteQuietly($tmpPath . '/' . $basename);

				return ToolResult::error(
					'Refused: the downloaded Joomla ' . $latest . ' package failed its checksum check, so it '
					. 'was deleted. Nothing was changed on the site. Try again later.'
				);
			}

			$package = InstallerHelper::unpack($tmpPath . '/' . $basename, true);

			if (!\is_array($package) || empty($package['extractdir']) || !is_dir($package['extractdir'])) {
				$this->deleteQuietly($tmpPath . '/' . $basename);

				return ToolResult::error(
					'Could not unpack the Joomla ' . $latest . ' package (' . $basename . '). Nothing was '
					. 'changed on the site. Check that the tmp folder is writable and has space.'
				);
			}

			// Before overwriting anything, make sure this is the package we expect.
			$packageVersion = $this->manifestVersion($package['extractdir'] . '/administrator/manifests/files/joomla.xml');

			if ($packageVersion === null || version_compare($packageVersion, $latest, '!=')) {
				InstallerHelper::cleanupInstall($tmpPath . '/' . $basename, $package['extractdir']);

				return ToolResult::error(
					'Refused: the downloaded package is not Joomla ' . $latest . ' (its manifest says '
					. ($packageVersion ?? 'nothing') . '). Nothing was changed on the site.'
				);
			}
		} catch (\Throwable $e) {
			return ToolResult::error(
				'Preparing the Joomla ' . $latest . ' update failed before any site file was changed: '
				. $e->getMessage()
			);
		}

		// From here on the site is being changed. JPATH_ROOT, not JPATH_BASE:
		// in the API application JPATH_BASE is the api/ folder.
		try {
			Folder::copy($package['extractdir'], JPATH_ROOT, '', true);
		} catch (\Throwable $e) {
			$this->cleanUpPackage($tmpPath . '/' . $basename, $package['extractdir']);

			return ToolResult::error(
				'Copying the Joomla ' . $latest . ' files over the site failed partway through: '
				. $e->getMessage() . '. The site is now a MIX of ' . $fromVersion . ' and ' . $latest
				. ' files. Restore from the backup, or fix the file permissions and run the update again '
				. 'from Joomla\'s web UI. The downloaded package was removed from the tmp folder.'
			);
		}

		// Finishing (database changes, removing old files) must happen in a NEW
		// request. This one already has the old version's classes loaded, and
		// whatever it loads from now on comes from the new files. Mixing the two
		// broke a 5.4 → 6.2 upgrade halfway in testing ("Too few arguments to
		// Installer::setAdapter()"). Joomla's web updater finishes in a separate
		// request for the same reason. Remember what the second call needs.
		$this->writeMarker([
			'from'       => $fromVersion,
			'to'         => $latest,
			'package'    => $tmpPath . '/' . $basename,
			'extractdir' => $package['extractdir'],
		]);
		$this->flushOpcache();

		return ToolResult::json([
			'done'           => false,
			'stage'          => 'files_copied',
			'from_version'   => $fromVersion,
			'target_version' => $latest,
			'next_step'      => 'Call apply_joomla_update again NOW with confirm=true and target_version='
				. $latest . ' to finish the upgrade.',
			'message'        => 'NOT FINISHED YET. The Joomla ' . $latest . ' files are in place, but the '
				. 'upgrade is only finished (database changes, old files removed, version recorded) by a '
				. 'second call to apply_joomla_update, which must run in a new request. Until then the site '
				. 'runs the new files on the old database and may show errors. Make the second call straight away.',
		]);
	}

	/**
	 * Second call: finish an upgrade whose files are already on disk. Runs in
	 * a fresh request, so every class Joomla loads here is the new version's.
	 */
	private function finish(string $onDisk, string $inDb, string $targetVersion): ToolResult
	{
		if ($targetVersion !== '' && version_compare($targetVersion, $onDisk, '!=')) {
			return ToolResult::error(
				'Refused: the Joomla files on disk are already ' . $onDisk . ' while the database still says '
				. $inDb . ', so the only thing left to do is finish the ' . $onDisk . ' upgrade. Call again with '
				. 'target_version=' . $onDisk . ' (or without target_version) to finish it.'
			);
		}

		@set_time_limit(0);
		@ignore_user_abort(true);

		$marker = $this->readMarker();
		$from   = (string) ($marker['from'] ?? $inDb);
		$model  = $this->getModel('com_joomlaupdate', 'Update');

		clearstatcache();

		try {
			$finalised = (bool) $model->finaliseUpgrade();
		} catch (\Throwable $e) {
			$finalised = false;
			$model->setError($e->getMessage());
		}

		$errors = [];

		foreach ((array) $model->getErrors() as $error) {
			$errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
		}

		if (!$finalised) {
			return ToolResult::error(
				'The Joomla ' . $onDisk . ' files are in place, but finishing the upgrade (database '
				. 'changes and removing old files) failed' . ($errors !== [] ? ': ' . implode(' | ', $errors) : '')
				. '. The site is in a transitional state. Check administrator/logs/joomla_update.php, then '
				. 'restore from the backup, or open System → Maintenance: Database and click Update Structure.'
			);
		}

		$sourceReset = method_exists($model, 'resetUpdateSource') ? $model->resetUpdateSource() : null;

		$this->cleanUpPackage((string) ($marker['package'] ?? ''), (string) ($marker['extractdir'] ?? ''));
		$this->cleanUp($from);
		$this->deleteMarker();

		try {
			$model->purge();
		} catch (\Throwable $e) {
			// Only clears the cached update list; the next check refills it.
		}

		$this->flushOpcache();

		// Read back what is actually installed now.
		$nowOnDisk = $this->versionOnDisk();
		$nowInDb   = $this->versionInDatabase();
		$verified  = $nowOnDisk !== null && $nowInDb !== null && version_compare($nowOnDisk, $nowInDb, '==');

		if (!$verified) {
			return ToolResult::error(
				'Finishing the Joomla ' . $onDisk . ' upgrade ran, but the result does not check out: '
				. 'libraries/src/Version.php says ' . ($nowOnDisk ?? 'unreadable') . ' and the database says '
				. ($nowInDb ?? 'unreadable') . '. Do not assume the site was updated. Check '
				. 'administrator/logs/joomla_update.php and System → Maintenance: Database.'
			);
		}

		$result = [
			'ok'              => true,
			'done'            => true,
			'from_version'    => $from,
			'applied_version' => $nowOnDisk,
			'verified'        => ['version_php' => $nowOnDisk, 'database' => $nowInDb],
			'message'         => 'Joomla core updated from ' . $from . ' to ' . $nowOnDisk
				. ', confirmed on disk and in the database. Visit administrator/ to check the site; '
				. 'if anything looks wrong, restore from your pre-update backup.',
		];

		if ($errors !== []) {
			$result['warnings'] = $errors;
		}

		if ($sourceReset === true) {
			$result['update_channel'] = 'Joomla switched its update channel back to "Default".';
		}

		if ($marker === []) {
			$result['note'] = 'This finished an upgrade started elsewhere, so the downloaded package '
				. '(Joomla_*_Update_Package.zip) and its unpacked install_* folder may still be in the tmp '
				. 'folder. They are safe to delete.';
		}

		return ToolResult::json($result);
	}

	/**
	 * How many problems System → Maintenance: Database lists for Joomla CMS, as
	 * the CLI updater checks. Only the count: errorsMessage also carries
	 * informational lines ("12 database changes were checked").
	 */
	private function schemaErrorCount(): int
	{
		try {
			$core    = ExtensionHelper::getExtensionRecord('joomla', 'file');
			$dbModel = $this->getModel('com_installer', 'Database');
			$dbModel->setState('filter.extension_id', (int) $core->extension_id);
			$items   = $dbModel->getItems();
			$core    = $items[0] ?? null;
		} catch (\Throwable $e) {
			// Couldn't run the check at all; don't block the update on that.
			return 0;
		}

		return \is_array($core) ? (int) ($core['errorsCount'] ?? 0) : 0;
	}

	private function markerPath(): string
	{
		return rtrim((string) Factory::getApplication()->get('tmp_path'), '/\\') . '/csmcpforj_joomla_update.json';
	}

	private function writeMarker(array $data): void
	{
		@file_put_contents($this->markerPath(), json_encode($data));
	}

	private function readMarker(): array
	{
		$raw = @file_get_contents($this->markerPath());

		return \is_string($raw) ? (json_decode($raw, true) ?: []) : [];
	}

	private function deleteMarker(): void
	{
		$this->deleteQuietly($this->markerPath());
	}

	private function cleanUpPackage(string $packageFile, string $extractDir): void
	{
		try {
			if ($packageFile !== '' || $extractDir !== '') {
				InstallerHelper::cleanupInstall($packageFile, $extractDir !== '' ? $extractDir : null);
			}
		} catch (\Throwable $e) {
		}
	}

	/**
	 * Joomla's rule for a major upgrade (UpdateCoreCommand::checkMajorUpgrade):
	 * this version's Backward Compatibility plugin must be off and the next
	 * version's must be on. On Joomla 5 the first is "compat", from 6 on it's
	 * "compat<major>".
	 */
	private function majorUpgradeProblems(): array
	{
		$current  = Version::MAJOR_VERSION <= 5 ? 'compat' : 'compat' . Version::MAJOR_VERSION;
		$next     = 'compat' . (Version::MAJOR_VERSION + 1);
		$problems = [];

		if (PluginHelper::isEnabled('behaviour', $current)) {
			$problems[] = 'the "' . $this->pluginName($current) . '" plugin (behaviour/' . $current . ') must be DISABLED.';
		}

		$nextRecord = ExtensionHelper::getExtensionRecord($next, 'plugin', 0, 'behaviour');

		if ((Version::MAJOR_VERSION <= 5 || $nextRecord) && !PluginHelper::isEnabled('behaviour', $next)) {
			$problems[] = 'the "' . $this->pluginName($next) . '" plugin (behaviour/' . $next . ') must be ENABLED.';
		}

		return $problems;
	}

	private function pluginName(string $element): string
	{
		$key = 'PLG_BEHAVIOUR_' . strtoupper($element);
		Factory::getLanguage()->load('plg_behaviour_' . $element . '.sys', JPATH_ADMINISTRATOR);
		$name = Text::_($key);

		return $name !== $key ? $name : 'Behaviour - ' . $element;
	}

	/**
	 * What the web UI's cleanUp() does, without the session it relies on:
	 * remove the leftover marker files, drop the namespace map so new classes
	 * are found, and fire onJoomlaAfterUpdate (the template override checker
	 * listens for it). The package itself goes through cleanUpPackage().
	 */
	private function cleanUp(string $fromVersion): void
	{
		foreach ([
			JPATH_ADMINISTRATOR . '/components/com_joomlaupdate/update.php',
			JPATH_ROOT . '/joomla.xml',
			JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php',
		] as $file) {
			$this->deleteQuietly($file);
		}

		try {
			PluginHelper::importPlugin('installer');
			$eventClass = '\\Joomla\\CMS\\Event\\Extension\\AfterJoomlaUpdateEvent';
			$event      = class_exists($eventClass)
				? new $eventClass('onJoomlaAfterUpdate', ['oldVersion' => $fromVersion])
				: new \Joomla\Event\Event('onJoomlaAfterUpdate', ['oldVersion' => $fromVersion]);
			Factory::getApplication()->getDispatcher()->dispatch('onJoomlaAfterUpdate', $event);
		} catch (\Throwable $e) {
			// The override check is a convenience; the update itself is done.
		}
	}

	private function deleteQuietly(string $file): void
	{
		try {
			if (is_file($file)) {
				File::delete($file);
			}
		} catch (\Throwable $e) {
		}
	}

	private function flushOpcache(): void
	{
		if (\function_exists('opcache_reset')) {
			@opcache_reset();
		}
	}

	private function manifestVersion(string $path): ?string
	{
		if (!is_file($path)) {
			return null;
		}

		$xml = @simplexml_load_file($path);

		return $xml && isset($xml->version) ? trim((string) $xml->version) : null;
	}

	/** MAJOR.MINOR.PATCH[-EXTRA] from libraries/src/Version.php as it is on disk now. */
	private function versionOnDisk(): ?string
	{
		$source = @file_get_contents(JPATH_LIBRARIES . '/src/Version.php');

		if ($source === false) {
			return null;
		}

		$parts = [];

		foreach (['MAJOR', 'MINOR', 'PATCH', 'EXTRA'] as $name) {
			if (!preg_match('/const\s+' . $name . "_VERSION\s*=\s*'?([^';\s]*)'?\s*;/", $source, $m)) {
				return null;
			}

			$parts[$name] = $m[1];
		}

		return $parts['MAJOR'] . '.' . $parts['MINOR'] . '.' . $parts['PATCH']
			. ($parts['EXTRA'] !== '' ? '-' . $parts['EXTRA'] : '');
	}

	/** The core extension's manifest cache version, read fresh from the database. */
	private function versionInDatabase(): ?string
	{
		try {
			$db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
			$query = $db->getQuery(true)
				->select($db->quoteName('manifest_cache'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('file'))
				->where($db->quoteName('element') . ' = ' . $db->quote('joomla'));
			$cache = (string) $db->setQuery($query)->loadResult();
		} catch (\Throwable $e) {
			return null;
		}

		$version = (new Registry($cache))->get('version');

		return \is_string($version) && $version !== '' ? $version : null;
	}
}
