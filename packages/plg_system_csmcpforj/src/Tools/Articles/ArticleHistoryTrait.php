<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * An article's version history (#__history), shared by the three version
 * tools (2.14.0).
 *
 * Read straight from the table rather than through com_contenthistory's
 * models: those are built for the admin modal (they read the item from the
 * request), and Joomla 5 and 6 store the rows differently enough that the
 * models' own restore differs too. Joomla 6 added `is_current` and
 * `is_legacy`, and its loadHistory() saves the WHOLE stored row, state and
 * publish dates included, which would let someone without Edit State publish
 * by restoring an old published version. So the restore tool saves only the
 * content fields (see ArticleHistoryTrait::$restorableFields) through
 * ArticleModel::save(), on both versions, and leaves publishing to
 * publish_article.
 *
 * Who may see the history: the same people who may edit the article (Edit, or
 * Edit Own on your own), as with the Versions button in the editor.
 */
trait ArticleHistoryTrait
{
	/** What a restore brings back. Everything else stays as it is now. */
	private static array $restorableFields = [
		'title', 'alias', 'introtext', 'fulltext', 'images', 'urls', 'attribs',
		'metadesc', 'metakey', 'metadata', 'note', 'created_by_alias',
	];

	/** Compared and reported, but never restored. */
	private static array $reportedOnlyFields = [
		'state', 'catid', 'featured', 'publish_up', 'publish_down', 'access', 'language', 'created_by',
	];

	private function historyEnabled(): bool
	{
		return (bool) ComponentHelper::getParams('com_content')->get('save_history', 0);
	}

	private function historyItemId(int $articleId): string
	{
		return 'com_content.article.' . $articleId;
	}

	/** @return string[] */
	private function historyColumns(): array
	{
		static $columns = null;

		if ($columns === null) {
			$columns = array_keys($this->db->getTableColumns('#__history'));
		}

		return $columns;
	}

	/**
	 * The versions of one article, newest first, without their data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function articleVersions(int $articleId): array
	{
		$db      = $this->db;
		$itemId  = $this->historyItemId($articleId);
		$columns = array_values(array_intersect(
			['version_id', 'version_note', 'save_date', 'editor_user_id', 'character_count', 'keep_forever', 'is_current', 'is_legacy'],
			$this->historyColumns()
		));

		$rows = (array) $db->setQuery(
			$db->getQuery(true)
				->select(array_map(static fn (string $c): string => $db->quoteName('h.' . $c), $columns))
				->select($db->quoteName('u.name', 'editor_name'))
				->from($db->quoteName('#__history', 'h'))
				->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('h.editor_user_id'))
				->where($db->quoteName('h.item_id') . ' = :item')
				->bind(':item', $itemId)
				->order($db->quoteName('h.save_date') . ' DESC')
				->order($db->quoteName('h.version_id') . ' DESC')
		)->loadAssocList();

		return array_map(static function (array $r): array {
			$out = [
				'version_id'      => (int) $r['version_id'],
				'save_date'       => (string) $r['save_date'],
				'version_note'    => (string) ($r['version_note'] ?? ''),
				'editor'          => ['id' => (int) $r['editor_user_id'], 'name' => (string) ($r['editor_name'] ?? '')],
				'character_count' => (int) ($r['character_count'] ?? 0),
				'keep_forever'    => (bool) (int) ($r['keep_forever'] ?? 0),
			];
			if (array_key_exists('is_current', $r)) {
				$out['is_current'] = (bool) (int) $r['is_current'];
			}

			return $out;
		}, $rows);
	}

	/**
	 * One version's stored row, checked to belong to this article.
	 *
	 * @return array{row: array<string, mixed>, meta: array<string, mixed>}
	 */
	private function articleVersion(int $articleId, int $versionId): array
	{
		$db = $this->db;

		$record = $db->setQuery(
			$db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__history'))
				->where($db->quoteName('version_id') . ' = :vid')
				->bind(':vid', $versionId, ParameterType::INTEGER)
		)->loadAssoc();

		// Same answer for "no such version" and "another item's version", so
		// the tool can't be used to probe other items' history.
		if (!$record || (string) $record['item_id'] !== $this->historyItemId($articleId)) {
			throw new McpException(-32602, 'Version ' . $versionId . ' is not in the history of article ' . $articleId
				. '. Use list_article_versions to see its versions.');
		}

		$row = json_decode((string) $record['version_data'], true);

		if (!is_array($row)) {
			throw new McpException(-32602, 'Version ' . $versionId . ' could not be read (its stored data is not valid JSON).');
		}

		return [
			'row'  => $row,
			'meta' => [
				'version_id'   => (int) $record['version_id'],
				'save_date'    => (string) $record['save_date'],
				'version_note' => (string) ($record['version_note'] ?? ''),
			],
		];
	}

	/** A JSON column from a stored row as an array (stored as a string or an object). */
	private function historyJson(mixed $value): array
	{
		if (is_string($value)) {
			$value = $value !== '' ? json_decode($value, true) : [];
		}

		return is_array($value) ? $value : (is_object($value) ? (array) json_decode((string) json_encode($value), true) : []);
	}

	/**
	 * The fields that differ between a stored version and the article now.
	 *
	 * @return string[]
	 */
	private function historyDiff(array $version, object $current): array
	{
		$changed = [];

		foreach (array_merge(self::$restorableFields, self::$reportedOnlyFields) as $field) {
			if (!array_key_exists($field, $version)) {
				continue;
			}

			$then = $version[$field];
			$now  = $current->$field ?? null;

			if (in_array($field, ['images', 'urls', 'attribs', 'metadata'], true)) {
				$a = $this->historyJson($then);
				$b = $this->historyJson($now);
				ksort($a);
				ksort($b);
				$same = json_encode($a) === json_encode($b);
			} else {
				$same = (string) ($then ?? '') === (string) ($now ?? '');
			}

			if (!$same) {
				$changed[] = $field;
			}
		}

		return $changed;
	}

	/** Edit, or Edit Own on your own article, as for the editor's Versions button. */
	private function requireHistoryAccess(User $actor, object $article): void
	{
		$this->requireArticleEdit($actor, $article);
	}
}
