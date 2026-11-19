<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Shared by check_database_schema and fix_database_schema (GitHub #48): the
 * report from System → Maintenance: Database, built by Joomla's own
 * com_installer Database model so both tools say exactly what that screen
 * says. The model's public methods (getItems, fix) are the same in 5.4 and 6.x.
 */
trait DatabaseSchemaTrait
{
	/** A fresh model every time: it caches the check and its error count per instance. */
	private function databaseModel(): object
	{
		Factory::getLanguage()->load('com_installer', JPATH_ADMINISTRATOR);

		$model = $this->getModel('com_installer', 'Database');
		$model->setState('list.start', 0);
		$model->setState('list.limit', 0);

		return $model;
	}

	/**
	 * One entry per extension the Database screen lists (those with a row in
	 * #__schemas and SQL update files on disk), core first.
	 *
	 * @return array<int, array<string, mixed>> keyed by extension id
	 */
	private function schemaReport(): array
	{
		$report = [];

		foreach ($this->databaseModel()->getItems() as $item) {
			$ext      = $item['extension'];
			$messages = array_values((array) ($item['errorsMessage'] ?? []));
			$status   = (array) ($item['results'] ?? []);
			$isCore   = $ext->type === 'file' && $ext->element === 'com_admin';
			$manifest = json_decode((string) $ext->manifest_cache);

			// Joomla's messages carry <strong>, and some extension names an <img>.
			$plain = static fn (string $s): string => trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));

			$report[(int) $ext->extension_id] = [
				'extension_id'           => (int) $ext->extension_id,
				'name'                   => $isCore ? 'Joomla CMS' : $plain((string) $ext->name),
				'type'                   => (string) $ext->type,
				'folder'                 => (string) $ext->folder,
				'client'                 => (int) $ext->client_id === 1 ? 'administrator' : 'site',
				'schema_in_database'     => (string) $ext->version_id,
				'schema_from_sql_files'  => (string) ($item['schema'] ?? ''),
				'manifest_cache_version' => (string) ($manifest->version ?? ''),
				'problems_count'         => (int) ($item['errorsCount'] ?? 0),
				// The model always ends the list with "N database changes were
				// checked" and "N database changes did not alter table structure".
				'problems'               => array_map($plain, \array_slice($messages, 0, -2)),
				'structure_errors'       => \count((array) ($status['error'] ?? [])),
				'changes_checked'        => \count((array) ($status['ok'] ?? [])),
				'changes_skipped'        => \count((array) ($status['skipped'] ?? [])),
			];
		}

		uasort($report, static fn (array $a, array $b): int => ($b['name'] === 'Joomla CMS') <=> ($a['name'] === 'Joomla CMS'));

		return $report;
	}

	/** @param array<int, array<string, mixed>> $report */
	private function problemTotal(array $report): int
	{
		return array_sum(array_column($report, 'problems_count'));
	}

	private function requireInstallerManager(\Joomla\CMS\User\User $actor): void
	{
		if (!$actor->authorise('core.manage', 'com_installer')) {
			throw new \Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException(
				-32002,
				$this->getName() . ' needs access to Extensions: Manage (core.manage on com_installer), the same permission Joomla asks for on System → Maintenance: Database.'
			);
		}
	}
}
