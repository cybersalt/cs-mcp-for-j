<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Defensive trait for any tool that performs a *permanent* delete on a Joomla
 * resource. Before allowing the hard delete, require the row(s) to already be
 * in the trash state (state = -2). This forces a two-step path — trash first
 * via the same tool's soft-delete path, then re-call with permanent=true —
 * which prevents an LLM from accidentally erasing live content with a single
 * mis-targeted call.
 *
 * Concept independently developed for cs-mcp-for-j after studying the same
 * problem space addressed by nikosdion/joomla-mcp-php's auto-trash-before-
 * delete workflow (AGPL-3.0) — no code was copied. The implementations
 * differ in approach: theirs auto-trashes any non-trashed target as a
 * convenience, ours refuses and asks the LLM to make the trash step
 * explicit so the AI's reasoning trail clearly records the two-step intent.
 */
trait RequiresTrashFirstTrait
{
	/**
	 * Throw \RuntimeException if any of the supplied row IDs in $tableName is
	 * not currently at $stateColumn = -2 (trashed). Use immediately before
	 * calling AdminModel::delete($ids) in a tool that supports permanent=true.
	 *
	 * @param array<int, int> $ids          Row IDs about to be hard-deleted
	 * @param string          $tableName    Joomla short table name (e.g. '#__content')
	 * @param string          $stateColumn  Column holding the publish state (default 'state')
	 * @param string          $resourceLabel Human label used in the error message
	 *                                       (e.g. 'article', 'module', 'user')
	 */
	protected function assertAlreadyTrashed(
		array $ids,
		string $tableName,
		string $stateColumn = 'state',
		string $resourceLabel = 'item'
	): void {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$ids = array_filter($ids, static fn(int $id): bool => $id > 0);
		if ($ids === []) {
			return;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);

		$query = $db->getQuery(true)
			->select($db->quoteName(['id', $stateColumn]))
			->from($db->quoteName($tableName))
			->whereIn($db->quoteName('id'), $ids);

		$rows = $db->setQuery($query)->loadAssocList() ?: [];

		$notTrashed = [];
		foreach ($rows as $row) {
			if ((int) $row[$stateColumn] !== -2) {
				$notTrashed[] = (int) $row['id'];
			}
		}

		if ($notTrashed === []) {
			return;
		}

		throw new \RuntimeException(sprintf(
			'Refused to permanently delete %s id(s) %s: not currently in trash. '
			. 'Permanent delete is irreversible. Call this tool first with '
			. 'permanent=false (or omit the flag) to move the %s(s) to trash, '
			. 'then re-call with permanent=true to erase them. This two-step '
			. 'path is intentional safety scaffolding for AI clients.',
			$resourceLabel,
			implode(', ', $notTrashed),
			$resourceLabel
		));
	}
}
