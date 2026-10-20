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
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * The edit-screen fields beyond the basics (images, urls, attribs, metadata,
 * note, version_note and the dates) come from ArticleFieldsTrait; see its
 * docblock for why every date is UTC. featured_up / featured_down are passed
 * in $data, where ArticleModel::save() hands them to featured() along with
 * `featured`.
 */
final class CreateArticleTool extends AbstractTool
{
	use ArticleAuthorTrait;
	use ArticleFieldsTrait;

	public function getName(): string { return 'create_article'; }

	public function getDescription(): string
	{
		return 'Create a new article in com_content. Required: title, catid, articletext. '
			. 'Use list_categories to find a valid catid. Optional tags: an array of tag ids (see list_tags). '
			. 'The author is you unless you pass created_by (a user id, username or email of an existing user); '
			. 'created_by_alias sets the "written by" name shown instead. '
			. 'The rest of the edit screen can be set too: images (intro and full-text image, alt text, image class, '
			. 'figure class, caption), urls (links A to C), attribs (the Options tab), metadata (robots, author, rights), '
			. 'note, version_note, and the dates created, publish_up, publish_down, featured_up and featured_down '
			. '(UTC, "YYYY-MM-DD HH:MM:SS"). '
			. 'Returns the new article id and edit URL.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'catid', 'articletext'],
			'properties' => [
				'title'              => ['type' => 'string', 'description' => 'Article title.'],
				'alias'              => ['type' => 'string', 'description' => 'URL alias. Auto-generated from title if omitted.'],
				'catid'              => ['type' => 'integer', 'description' => 'Category id. Use list_categories.'],
				'articletext'        => ['type' => 'string', 'description' => 'Full HTML body. <hr id="system-readmore"> splits intro/full text.'],
				'state'              => ['type' => 'integer', 'enum' => [0, 1, 2, -2], 'description' => '0=unpublished, 1=published, 2=archived, -2=trashed.'],
				'language'           => ['type' => 'string', 'description' => 'Language tag, e.g. "en-GB" or "*".'],
				'access'             => ['type' => 'integer', 'description' => 'Viewing access level id (1=Public).'],
				'metadesc'           => ['type' => 'string'],
				'metakey'            => ['type' => 'string'],
				'image_intro'        => ['type' => 'string', 'description' => 'Shortcut for images.image_intro. If both are given, images wins.'],
				'image_intro_alt'    => ['type' => 'string', 'description' => 'Shortcut for images.image_intro_alt. If both are given, images wins.'],
				'image_fulltext'     => ['type' => 'string', 'description' => 'Shortcut for images.image_fulltext. If both are given, images wins.'],
				'image_fulltext_alt' => ['type' => 'string', 'description' => 'Shortcut for images.image_fulltext_alt. If both are given, images wins.'],
				'featured'           => ['type' => 'integer', 'enum' => [0, 1]],
				'tags'               => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids to assign. Use list_tags to find them.'],
				'created_by'         => ['type' => 'string', 'description' => 'The article\'s author: a user id, username or email address. Must match exactly one existing user. Defaults to you.'],
				'created_by_alias'   => ['type' => 'string', 'description' => 'Author alias shown instead of the author\'s name.'],
			] + $this->articleFieldsSchema(false),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title = $this->requireString($arguments, 'title');
		$catid = $this->requirePositiveInt($arguments, 'catid');
		$body  = $this->requireString($arguments, 'articletext');

		// Validated before anything is written, so a bad author creates nothing.
		$author = array_key_exists('created_by', $arguments)
			? $this->resolveArticleAuthor($arguments['created_by'])
			: ['id' => (int) $actor->id, 'name' => (string) $actor->name, 'username' => (string) $actor->username];

		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		// Every key the admin form saves, empty, so the row looks like one
		// created in the admin. Then the old top-level shortcuts, then `images`.
		$images = [
			'image_intro'              => '',
			'image_intro_alt'          => '',
			'image_intro_alt_empty'    => '',
			'imgclass_intro'           => '',
			'float_intro'              => '',
			'image_intro_caption'      => '',
			'image_fulltext'           => '',
			'image_fulltext_alt'       => '',
			'image_fulltext_alt_empty' => '',
			'imgclass_fulltext'        => '',
			'float_fulltext'           => '',
			'image_fulltext_caption'   => '',
		];
		$shortcuts = array_intersect_key($arguments, array_flip(['image_intro', 'image_intro_alt', 'image_fulltext', 'image_fulltext_alt']));
		if ($shortcuts !== []) {
			$images = $this->mergeJsonPatch($images, $this->articleJsonArg($shortcuts, 'images'));
		}
		if (array_key_exists('images', $arguments)) {
			$images = $this->mergeJsonPatch($images, $this->articleJsonArg($arguments['images'], 'images'));
		}

		$json = ['images' => $images];
		foreach (['urls', 'attribs', 'metadata'] as $column) {
			$json[$column] = array_key_exists($column, $arguments)
				? $this->mergeJsonPatch([], $this->articleJsonArg($arguments[$column], $column))
				: [];
		}

		// Dates, UTC. Kept for the read-back check.
		$askedDates = [];
		foreach (['created' => false, 'publish_up' => true, 'publish_down' => true] as $key => $nullable) {
			if (array_key_exists($key, $arguments)) {
				$askedDates[$key] = $this->articleDate($arguments[$key], $key, $nullable);
			}
		}

		$featured = isset($arguments['featured']) ? (int) $arguments['featured'] : 0;
		$featuredDates = ['featured_up' => null, 'featured_down' => null];
		foreach (array_keys($featuredDates) as $key) {
			if (!array_key_exists($key, $arguments)) {
				continue;
			}

			$featuredDates[$key] = $this->articleDate($arguments[$key], $key, true);

			if ($featuredDates[$key] !== null) {
				if ($featured !== 1) {
					throw new McpException(-32602, $key . ' only applies to a featured article. Pass featured: 1 as well.');
				}

				$askedDates[$key] = $featuredDates[$key];
			}
		}

		$note        = array_key_exists('note', $arguments) ? $this->articleShortText($arguments['note'], 'note') : null;
		$versionNote = array_key_exists('version_note', $arguments) ? $this->articleShortText($arguments['version_note'], 'version_note') : null;

		$data = [
			'id'          => 0,
			'title'       => $title,
			'alias'       => $alias,
			'catid'       => $catid,
			'articletext' => $body,
			'state'       => isset($arguments['state']) ? (int) $arguments['state'] : 0,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'featured'    => $featured,
			'featured_up'   => $featuredDates['featured_up'],
			'featured_down' => $featuredDates['featured_down'],
			'metadesc'    => (string) ($arguments['metadesc'] ?? ''),
			'metakey'     => (string) ($arguments['metakey'] ?? ''),
			// Strings, so ArticleModel::save() skips its urls check that
			// reads the raw form POST (which an MCP request does not have).
			'images'      => (string) new Registry($json['images']),
			'urls'        => (string) new Registry($json['urls']),
			'attribs'     => (string) new Registry($json['attribs']),
			'metadata'    => (string) new Registry($json['metadata']),
			'created_by'  => $author['id'],
			'tags'        => $this->resolveTags($arguments, 'com_content.article', 0),
		];
		foreach (['created', 'publish_up', 'publish_down'] as $key) {
			if (array_key_exists($key, $askedDates)) {
				$data[$key] = $askedDates[$key];
			}
		}
		if ($note !== null) {
			$data['note'] = $note;
		}
		if ($versionNote !== null) {
			$data['version_note'] = $versionNote;
		}
		if (array_key_exists('created_by_alias', $arguments)) {
			$data['created_by_alias'] = $this->articleAuthorAlias($arguments['created_by_alias']);
		}

		$model  = $this->getModel('com_content', 'Article');
		$result = $this->withArticleVersionNote($versionNote, fn (): array => $this->saveAdminModel($model, $data));

		if ($result['id'] <= 0) {
			return ToolResult::error('com_content rejected the article: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = [
			'ok'       => true,
			'id'       => $result['id'],
			'title'    => $title,
			'alias'    => $alias,
			'catid'    => $catid,
			'state'    => $data['state'],
			'created_by' => $author,
			'edit_url' => 'index.php?option=com_content&task=article.edit&id=' . $result['id'],
		];
		if (array_key_exists('created_by_alias', $data)) {
			$response['created_by_alias'] = $data['created_by_alias'];
		}

		// Read the author back: the row exists now, so a mismatch is reported
		// as a warning on a created article, not as a failure to create it.
		$stored = $this->storedArticleAuthor($result['id']);
		if ($stored !== null && $stored['created_by'] !== $author['id']) {
			$response['author_warning'] = 'The article was created, but its stored author is user '
				. $stored['created_by'] . ', not ' . $author['id'] . '. Fix it with update_article.';
		}
		$dateWarning = $this->articleDateWarning($result['id'], $askedDates);
		if ($dateWarning !== null) {
			$response['date_warning'] = $dateWarning;
		}
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
