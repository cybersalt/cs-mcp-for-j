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
 * Apply the available Joomla core update: download the package, extract it,
 * run the upgrade. Destructive and effectively irreversible without restoring
 * from a backup. ALWAYS require an explicit `confirm=true` flag from the
 * caller — the AI can't trip into this tool through an ambiguous chain.
 *
 * Implementation is deliberately conservative: we call into Joomla's
 * com_joomlaupdate UpdateModel methods in the same sequence the admin Web UI
 * uses, and surface any failure verbatim. If a step fails partway through,
 * the site may be in a transitional state — the operator (or their AI) needs
 * to either retry or restore from backup. We document that loudly in the
 * description so the AI is confident about when to call this.
 *
 * For complex update scenarios (Joomla 5 → 6 major upgrade, extensions with
 * known compat issues, large sites), the operator should still use the web
 * UI directly. This tool is appropriate for routine point releases on
 * straightforward sites.
 */
final class ApplyJoomlaUpdateTool extends AbstractTool
{
	public function getName(): string { return 'apply_joomla_update'; }

	public function getDescription(): string
	{
		return 'Apply the currently-available Joomla core update: download the package, '
			. 'extract it, and run the upgrade in sequence. DESTRUCTIVE and effectively '
			. 'irreversible without restoring from a backup — always make a fresh backup '
			. 'BEFORE calling this tool. Requires explicit confirm=true; without that '
			. 'flag the tool refuses, so the AI cannot trip into this through an '
			. 'ambiguous chain. Always call check_joomla_update and '
			. 'joomla_update_healthcheck first; only call this when the healthcheck '
			. 'verdict is "ok" or you have explicit user confirmation to proceed '
			. 'despite warnings. For Joomla 5 → 6 major upgrades or sites with extension '
			. 'compat warnings, use the web UI directly instead.';
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
		if (($arguments['confirm'] ?? null) !== true) {
			return ToolResult::error(
				'Refused: apply_joomla_update requires explicit confirm=true. This is '
				. 'a destructive irreversible operation. Surface the implications to '
				. 'the user (current version, target version, the need for a backup), '
				. 'wait for their explicit go-ahead, then re-call with confirm=true.'
			);
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// Confirm there's actually an update queued — if there isn't, we'd
		// download a no-op and waste cycles.
		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('file'))
			->where($db->quoteName('element') . ' = ' . $db->quote('joomla'));
		$extensionId = (int) $db->setQuery($query)->loadResult();

		$query = $db->getQuery(true)
			->select($db->quoteName(['version', 'data']))
			->from($db->quoteName('#__updates'))
			->where($db->quoteName('extension_id') . ' = ' . $extensionId)
			->order($db->quoteName('version') . ' DESC');
		$updateRow = $db->setQuery($query)->loadAssoc();

		if (!$updateRow) {
			return ToolResult::error(
				'No queued Joomla update found in #__updates. Call check_joomla_update '
				. 'first so Joomla\'s update poller sees the latest available version.'
			);
		}

		// Load Joomla's own update model and walk it through the standard
		// download → install sequence.
		$update = $this->getModel('com_joomlaupdate', 'Update');

		try {
			$downloaded = $update->download();
			if (!is_array($downloaded) || empty($downloaded['basename'])) {
				return ToolResult::error('Joomla UpdateModel::download() returned no basename — package fetch failed.');
			}

			if (!$update->createUpdateFile($downloaded['basename'])) {
				return ToolResult::error('Joomla UpdateModel::createUpdateFile() failed — could not stage the update marker.');
			}

			// finaliseUpgrade() is the apply step in J5 — invokes Joomla's
			// restore.php sequence to extract over the install and run the
			// post-install schema migrations.
			$result = $update->finaliseUpgrade();
		} catch (\Throwable $e) {
			return ToolResult::error(
				'apply_joomla_update aborted partway through: ' . $e->getMessage()
				. ' — the site may be in a transitional state. Restore from backup '
				. 'or use Joomla\'s web UI to retry.'
			);
		}

		if (!$result) {
			return ToolResult::error(
				'Joomla UpdateModel::finaliseUpgrade() returned false. The site may '
				. 'be in a transitional state. Check Joomla\'s logs (administrator/logs/) '
				. 'for details and consider restoring from backup.'
			);
		}

		return ToolResult::json([
			'ok'              => true,
			'applied_version' => (string) $updateRow['version'],
			'message'         => 'Joomla core updated to ' . $updateRow['version']
				. '. Verify the site by visiting administrator/ — if anything looks '
				. 'wrong, restore from your pre-update backup.',
		]);
	}
}
