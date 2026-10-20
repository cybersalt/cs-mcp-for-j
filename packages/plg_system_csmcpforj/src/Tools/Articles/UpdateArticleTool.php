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
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

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
 *
 *   `created_by` / `created_by_alias` (2.9.2, Susan again) go through the save
 *   like any other scalar, and only when supplied. Content table store() only
 *   fills `created_by` from the current user on a NEW row, so an update keeps
 *   whatever is bound. After the save the stored values are read back and the
 *   call fails if they differ from what was asked for.
 *
 *   The rest of the edit screen (the release after 2.9.2, checked against Joomla 6.2.0's
 *   forms/article.xml and ArticleModel::save()):
 *
 *   - `images`, `urls`, `attribs`, `metadata` are JSON columns. Each is sent
 *     ONLY when its argument is supplied, and then as the stored blob with the
 *     caller's keys merged on (mergeJsonPatch), so keys the caller did not
 *     name keep their values. `urls` goes over as a JSON string, not an array:
 *     ArticleModel::save() runs an array of urls through a check that reads
 *     the raw jform POST, which an MCP request does not have. We do its
 *     punycode step ourselves in ArticleFieldsTrait.
 *   - `note`, `created`, `publish_up`, `publish_down` are plain columns, sent
 *     only when supplied. Dates are taken as UTC and bound as-is (no form
 *     filter runs on this path, unlike the admin form's user_utc and the core
 *     API above), then read back and any difference is reported.
 *   - ⚠️ FEATURED DATES. ArticleModel::save() calls featured() whenever
 *     `featured` is in $data, passing `$data['featured_up'] ?? null`, and
 *     featured() writes those straight to #__content_frontpage. So sending
 *     `featured` alone WIPES an already-featured article's Start/Finish
 *     Featured dates. Up to 2.9.2 this tool did exactly that. Now, when any of
 *     featured / featured_up / featured_down is supplied, all three are sent,
 *     the missing ones re-supplied from the loaded row; when none is supplied
 *     none is sent and featured() is not called.
 *   - `version_note` is not a column; it is passed where Joomla 5 and 6 each
 *     look for it (see ArticleFieldsTrait::withArticleVersionNote()).
 */
final class UpdateArticleTool extends AbstractTool
{
	use ArticleAuthorTrait;
	use ArticleFieldsTrait;

	private const UPDATABLE_SCALARS = [
		'title', 'alias', 'catid', 'state', 'language', 'access',
		'metadesc', 'metakey',
	];

	private const JSON_COLUMNS = ['images', 'urls', 'attribs', 'metadata'];

	public function getName(): string { return 'update_article'; }

	public function getDescription(): string
	{
		return 'Update an existing article. Required: id. Only the fields you supply are changed; '
			. 'all others are preserved, including tags. Use articletext to replace the body, or set state to '
			. 'publish (1), unpublish (0), archive (2), or trash (-2). Pass tags (an array of tag ids) to '
			. 'replace the tags on the article; an empty array removes them all. Pass hits to set the '
			. 'article\'s hit count (for example when merging duplicates); a call with only id and hits '
			. 'changes nothing else and does not re-save the article. Pass created_by (a user id, username or '
			. 'email of an existing user) to change the author, and created_by_alias to set the "written by" '
			. 'name shown instead of the author\'s; an empty string clears the alias. '
			. 'The rest of the edit screen is here too: images (intro and full-text image, alt text, image class, '
			. 'figure class, caption), urls (links A to C), attribs (the Options tab), metadata (robots, author, rights), '
			. 'note, version_note, and the dates created, publish_up, publish_down, featured_up and featured_down. '
			. 'images, urls, attribs and metadata are objects: pass only the keys to change; they are merged onto '
			. 'what is stored, and a null value removes a key. Dates are UTC ("YYYY-MM-DD HH:MM:SS"), the same as '
			. 'get_article returns them.';
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
				'featured'    => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 features the article, 0 unfeatures it. Its Start/Finish Featured dates are kept unless you also pass featured_up / featured_down.'],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
				'metadesc'    => ['type' => 'string'],
				'metakey'     => ['type' => 'string'],
				'tags'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids. Replaces the tags on the article; [] removes them all. Omit to keep the current tags.'],
				'hits'        => ['type' => 'integer', 'minimum' => 0, 'description' => 'Sets the hit count. Omit to keep the current count. Written directly; does not change the modified date.'],
				'created_by'  => ['type' => 'string', 'description' => 'The article\'s author: a user id, username or email address. Must match exactly one existing user. Omit to keep the current author.'],
				'created_by_alias' => ['type' => 'string', 'description' => 'Author alias shown instead of the author\'s name. An empty string clears it. Omit to keep the current alias.'],
			] + $this->articleFieldsSchema(true),
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
		$author = null;
		if (array_key_exists('created_by', $arguments)) {
			$author             = $this->resolveArticleAuthor($arguments['created_by']);
			$data['created_by'] = $author['id'];
		}
		if (array_key_exists('created_by_alias', $arguments)) {
			$data['created_by_alias'] = $this->articleAuthorAlias($arguments['created_by_alias']);
		}
		if (array_key_exists('note', $arguments)) {
			$data['note'] = $this->articleShortText($arguments['note'], 'note');
		}
		$versionNote = null;
		if (array_key_exists('version_note', $arguments)) {
			$versionNote          = $this->articleShortText($arguments['version_note'], 'version_note');
			$data['version_note'] = $versionNote;
		}

		// Row dates, UTC, only the ones supplied. Kept for the read-back check.
		$askedDates = [];
		foreach (['created' => false, 'publish_up' => true, 'publish_down' => true] as $key => $nullable) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $askedDates[$key] = $this->articleDate($arguments[$key], $key, $nullable);
			}
		}

		// Featured: send all three or none. ArticleModel::save() passes
		// featured_up/down to featured() whenever `featured` is present, and a
		// missing one is written as NULL. See the class docblock.
		if (array_intersect(['featured', 'featured_up', 'featured_down'], array_keys($arguments)) !== []) {
			$featured = array_key_exists('featured', $arguments) ? $arguments['featured'] : (int) $existing->featured;
			if (!in_array($featured, [0, 1, '0', '1'], true)) {
				throw new McpException(-32602, 'featured must be 0 or 1; got ' . json_encode($featured) . '.');
			}
			$featured         = (int) $featured;
			$data['featured'] = $featured;

			foreach (['featured_up', 'featured_down'] as $key) {
				if (!array_key_exists($key, $arguments)) {
					$current    = $featured === 1 ? ($existing->$key ?? null) : null;
					$data[$key] = ($current === null || $current === '') ? null : (string) $current;
					continue;
				}

				$data[$key] = $this->articleDate($arguments[$key], $key, true);

				if ($featured === 1) {
					$askedDates[$key] = $data[$key];
				} elseif ($data[$key] !== null) {
					throw new McpException(-32602, $key . ' only applies to a featured article, and article ' . $id
						. (array_key_exists('featured', $arguments) ? ' would be unfeatured by this call' : ' is not featured')
						. '. Pass featured: 1 as well.');
				}
			}
		}

		// JSON columns: the stored blob with the caller's keys merged on, and
		// only when the argument is supplied.
		foreach (self::JSON_COLUMNS as $column) {
			if (!array_key_exists($column, $arguments)) {
				continue;
			}

			$merged = $this->mergeJsonPatch($existing->$column ?? null, $this->articleJsonArg($arguments[$column], $column));

			// urls as a string, so ArticleModel::save() skips its raw-POST check.
			$data[$column] = $column === 'urls' ? (string) new Registry($merged) : $merged;
		}

		$data['modified_by'] = (int) $actor->id;

		// Always send tags: Joomla's Taggable behaviour deletes them when the
		// key is missing. See AbstractTool::resolveTags().
		$data['tags'] = $this->resolveTags($arguments, 'com_content.article', $id);

		$result = $this->withArticleVersionNote($versionNote, fn (): array => $this->saveAdminModel($model, $data));

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

		// The author is the point of the call when it is supplied, so check it
		// landed rather than trusting save()'s return value.
		if ($author !== null || array_key_exists('created_by_alias', $data)) {
			$stored = $this->storedArticleAuthor($id);
			$wrong  = [];

			if ($author !== null && ($stored['created_by'] ?? null) !== $author['id']) {
				$wrong[] = 'created_by is ' . json_encode($stored['created_by'] ?? null) . ', not ' . $author['id'];
			}
			if (array_key_exists('created_by_alias', $data) && ($stored['created_by_alias'] ?? null) !== $data['created_by_alias']) {
				$wrong[] = 'created_by_alias is ' . json_encode($stored['created_by_alias'] ?? null) . ', not ' . json_encode($data['created_by_alias']);
			}
			if ($wrong !== []) {
				return ToolResult::error('Article ' . $id . ' was saved, but the author did not change as asked: '
					. implode('; ', $wrong) . '.' . ($result['error'] !== '' ? ' Joomla said: ' . $result['error'] : ''));
			}
		}

		// After the save, so the model's write of the loaded row cannot undo it.
		if ($hits !== null) {
			$this->writeHits($id, $hits);
		}

		// Dates are compared with what landed: Joomla adjusts some on save.
		$dateWarning = $this->articleDateWarning($id, $askedDates);

		// What the caller asked to change. Not array_keys($data): that also
		// holds what was re-supplied to keep it (tags, the featured dates).
		$changed = array_values(array_diff(array_keys($arguments), ['id', 'version_note']));

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
		if ($author !== null) {
			$response['created_by']          = $author;
			$response['previous_created_by'] = (int) $existing->created_by;
		}
		if (array_key_exists('created_by_alias', $data)) {
			$response['created_by_alias']          = $data['created_by_alias'];
			$response['previous_created_by_alias'] = (string) $existing->created_by_alias;
		}
		if ($versionNote !== null) {
			$response['version_note'] = $versionNote;
		}
		if ($dateWarning !== null) {
			$response['date_warning'] = $dateWarning;
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
