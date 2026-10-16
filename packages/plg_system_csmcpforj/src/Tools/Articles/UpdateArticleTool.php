<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * @partial-save-safe com_content's ArticleModel::save() binds only the keys it is
 *   given; it does not read absent keys off $data the way com_menus' ItemModel
 *   does. Verified behaviourally 2026-09-18 on a live Joomla 5 site in an
 *   America/Vancouver (UTC-7) timezone: created an article, read the full row,
 *   ran update_article twice with ONLY `title`, re-read after each. `created`,
 *   `publish_up` and every untouched column were byte-identical; only `modified`
 *   advanced, which is correct.
 *
 *   ⚠️ TAGS were the exception that check could not see (2.9.0): they live in
 *   #__contentitem_tag_map, not on the article row, and Joomla's Taggable
 *   behaviour deletes them whenever `tags` is absent from the save. Found by
 *   Susan on cybersalt.org, 2026-10-15. The tool now always sends tags,
 *   re-supplying the current ones when the caller passes none. Verified on
 *   mcpfree the same day: a metadesc-only update keeps both tags.
 *
 *   ⚠️ Do NOT use Joomla's core web-services API (PATCH /v1/content/articles/<id>)
 *   as a substitute for this tool. The same test against that endpoint shifted
 *   BOTH `created` and `publish_up` forward by the site's UTC offset on EVERY
 *   call — two PATCHes put an article 14 hours into the future, at which point
 *   Joomla stops publishing it while `state` still reads 1 and the body reads
 *   correct. That is a core bug, not ours, and this tool is the safe path.
 *   See cs-mcp-for-j#32.
 *
 *   `hits` (2.9.0, requested by Susan for merging duplicate articles) is written
 *   straight to #__content, the same way Joomla's own hit counter writes it,
 *   and never goes through ArticleModel::save(). A call that changes only hits
 *   skips the save entirely, so content plugins that rewrite the text on save
 *   (ReReplacer appended a bio on every save on cybersalt.org) and the
 *   `modified` date are left alone.
 */
final class UpdateArticleTool extends AbstractTool
{
	private const UPDATABLE_SCALARS = [
		'title', 'alias', 'catid', 'state', 'language', 'access',
		'metadesc', 'metakey', 'featured',
	];

	public function getName(): string { return 'update_article'; }

	public function getDescription(): string
	{
		return 'Update an existing article. Required: id. Only the fields you supply are changed; '
			. 'all others are preserved, including tags. Use articletext to replace the body, or set state to '
			. 'publish (1), unpublish (0), archive (2), or trash (-2). Pass tags (an array of tag ids) to '
			. 'replace the tags on the article; an empty array removes them all. Pass hits to set the '
			. 'article\'s hit count (for example when merging duplicates); a call with only id and hits '
			. 'changes nothing else and does not re-save the article.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id'          => ['type' => 'integer', 'description' => 'Article id to update.'],
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'catid'       => ['type' => 'integer'],
				'articletext' => ['type' => 'string', 'description' => 'Replacement HTML body.'],
				'state'       => ['type' => 'integer', 'enum' => [0, 1, 2, -2]],
				'featured'    => ['type' => 'integer', 'enum' => [0, 1]],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
				'tags'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids. Replaces the tags on the article; [] removes them all. Omit to keep the current tags.'],
				'hits'        => ['type' => 'integer', 'minimum' => 0, 'description' => 'Sets the hit count. Omit to keep the current count. Written directly; does not change the modified date.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_content', 'Article');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$hits = null;
		if (array_key_exists('hits', $arguments)) {
			$hits = filter_var($arguments['hits'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
			if ($hits === false) {
				throw new McpException(-32602, 'hits must be a whole number of 0 or more; got ' . json_encode($arguments['hits']) . '.');
			}
		}

		// Hits only: write the count and stop, without re-saving the article.
		if ($hits !== null && array_diff(array_keys($arguments), ['id', 'hits']) === []) {
			$this->writeHits($id, $hits);

			return ToolResult::json([
				'ok'             => true,
				'id'             => $id,
				'fields_changed' => ['hits'],
				'hits'           => $hits,
				'previous_hits'  => (int) $existing->hits,
				'edit_url'       => 'index.php?option=com_content&task=article.edit&id=' . $id,
			]);
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE_SCALARS as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		if (array_key_exists('articletext', $arguments)) {
			$data['articletext'] = (string) $arguments['articletext'];
		}
		$data['modified_by'] = (int) $actor->id;

		// Always send tags: Joomla's Taggable behaviour deletes them when the
		// key is missing. See AbstractTool::resolveTags().
		$data['tags'] = $this->resolveTags($arguments, 'com_content.article', $id);

		$result = $this->saveAdminModel($model, $data);

		// On update, the row already exists with $id — the model's state id is
		// less critical than on create, but a "false" save() with no fatal
		// error still means the row was updated successfully. Treat it as
		// success and surface the post-save warning as a hint.
		if (!$result['ok'] && $result['error'] !== '' && $result['id'] <= 0) {
			// Confirm the update actually happened by re-reading.
			$check = $model->getItem($id);
			if (!$check || empty($check->id)) {
				return ToolResult::error('com_content rejected the update: ' . $result['error']);
			}
		}

		// After the save, so the model's write of the loaded row cannot undo it.
		if ($hits !== null) {
			$this->writeHits($id, $hits);
		}

		$changed = array_values(array_diff(array_keys($data), array_merge(['id', 'modified_by'], array_key_exists('tags', $arguments) ? [] : ['tags'])));
		if ($hits !== null) {
			$changed[] = 'hits';
		}

		$response = [
			'ok'             => true,
			'id'             => $id,
			'fields_changed' => $changed,
			'edit_url'       => 'index.php?option=com_content&task=article.edit&id=' . $id,
		];
		if ($hits !== null) {
			$response['hits']          = $hits;
			$response['previous_hits'] = (int) $existing->hits;
		}
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}

	private function writeHits(int $id, int $hits): void
	{
		$this->db->setQuery(
			$this->db->getQuery(true)
				->update($this->db->quoteName('#__content'))
				->set($this->db->quoteName('hits') . ' = ' . $hits)
				->where($this->db->quoteName('id') . ' = ' . $id)
		)->execute();
	}
}
