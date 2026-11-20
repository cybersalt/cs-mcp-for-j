<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Bring an article's content back from a stored version (2.14.0).
 *
 * Saves only the content fields (ArticleHistoryTrait::$restorableFields)
 * through ArticleModel::save(), so the restore is itself a new version and
 * can be undone the same way. State, category, publish dates, featured,
 * access, language and author stay as they are now; the answer lists which of
 * them differed in the old version, so the app can say so. Publishing stays
 * with publish_article (Edit State).
 *
 * Tags are re-supplied from the article's current tags (AbstractTool::
 * resolveTags), because a save without `tags` deletes them.
 */
final class RestoreArticleVersionTool extends AbstractTool
{
	use ArticleAclTrait;
	use ArticleFieldsTrait;
	use ArticleHistoryTrait;

	public function getName(): string { return 'restore_article_version'; }

	public function getDescription(): string
	{
		return 'Restore an article\'s content from a saved version (version_id from list_article_versions): title, alias, '
			. 'text, images, links, options, meta description/keywords, metadata, note and author alias come back. '
			. 'State, category, publish dates, featured, access, language and author are NOT changed; the answer lists '
			. 'any of them that were different in that version. The restore is saved as a new version, so it can be '
			. 'undone the same way. Needs Edit (or Edit Own) on the article.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id', 'version_id'],
			'properties' => [
				'id'           => ['type' => 'integer', 'description' => 'Article id.'],
				'version_id'   => ['type' => 'integer', 'description' => 'Version id from list_article_versions.'],
				'version_note' => ['type' => 'string', 'description' => 'Note for the new version. Defaults to "Restored from version <id> (<date>)".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);

		$id      = $this->requirePositiveInt($arguments, 'id');
		$vid     = $this->requirePositiveInt($arguments, 'version_id');
		$model   = $this->getModel('com_content', 'Article');
		$article = $model->getItem($id);

		if (!$article || empty($article->id)) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$this->requireHistoryAccess($actor, $article);

		$version = $this->articleVersion($id, $vid);
		$row     = $version['row'];
		$differs = $this->historyDiff($row, $article);

		$note = array_key_exists('version_note', $arguments)
			? $this->articleShortText($arguments['version_note'], 'version_note')
			: 'Restored from version ' . $vid . ' (' . $version['meta']['save_date'] . ' UTC)';

		$data = ['id' => $id, 'modified_by' => (int) $actor->id];

		foreach (['title', 'alias', 'metadesc', 'metakey', 'note', 'created_by_alias'] as $field) {
			if (array_key_exists($field, $row)) {
				$data[$field] = (string) ($row[$field] ?? '');
			}
		}

		if (array_key_exists('introtext', $row) || array_key_exists('fulltext', $row)) {
			$intro = (string) ($row['introtext'] ?? '');
			$full  = (string) ($row['fulltext'] ?? '');

			$data['articletext'] = trim($full) !== '' ? $intro . '<hr id="system-readmore">' . $full : $intro;
		}

		// JSON columns as strings: ArticleModel::save() runs an array of urls
		// through a check that reads the raw form POST (see UpdateArticleTool).
		foreach (['images', 'urls', 'attribs', 'metadata'] as $column) {
			if (array_key_exists($column, $row)) {
				$data[$column] = (string) new Registry($this->historyJson($row[$column]));
			}
		}

		$data['tags'] = $this->resolveTags([], 'com_content.article', $id);

		$result = $this->withArticleVersionNote($note, fn (): array => $this->saveAdminModel($model, $data));

		if (!$result['ok'] && $result['error'] !== '') {
			$check = $model->getItem($id);
			if (!$check || (string) $check->title !== (string) ($data['title'] ?? $check->title)) {
				return ToolResult::error('com_content refused the restore: ' . $result['error']);
			}
		}

		$notRestored = array_values(array_intersect($differs, self::$reportedOnlyFields));
		$response    = [
			'ok'              => true,
			'id'              => $id,
			'restored_from'   => $version['meta'],
			'fields_restored' => array_values(array_intersect($differs, self::$restorableFields)),
			'version_note'    => $note,
			'edit_url'        => 'index.php?option=com_content&task=article.edit&id=' . $id,
		];

		if ($notRestored !== []) {
			$then = [];
			foreach ($notRestored as $field) {
				$then[$field] = ['then' => $row[$field] ?? null, 'now' => $article->$field ?? null];
			}
			$response['not_restored'] = $then;
			$response['note'] = 'These differed in that version and were left as they are now. Change them with '
				. 'update_article or publish_article / unpublish_article if needed (publishing needs Edit State).';
		}
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}

		return ToolResult::json($response);
	}
}
