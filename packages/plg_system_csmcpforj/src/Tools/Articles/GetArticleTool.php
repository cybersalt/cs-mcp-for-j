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

final class GetArticleTool extends AbstractTool
{
	public function getName(): string { return 'get_article'; }

	public function getDescription(): string
	{
		return 'Fetch a single article by id, returning every field on its edit screen: core fields, resolved category title, '
			. 'author (created_by as {id, name, username}, plus created_by_alias), tags (array of {id, title}), note, '
			. 'images (intro and full-text image, alt text, image class, figure class, caption), urls (links A to C), '
			. 'attribs (the Options tab), metadata (robots, author, rights), the featured dates and the version number. '
			. 'All dates (created, modified, publish_up, publish_down, featured_up, featured_down) are UTC, '
			. 'in the same format update_article accepts.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Article id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('a.id'), $this->db->quoteName('a.title'), $this->db->quoteName('a.alias'),
				$this->db->quoteName('a.catid'), $this->db->quoteName('c.title', 'category_title'),
				$this->db->quoteName('a.introtext'), $this->db->quoteName('a.fulltext'),
				$this->db->quoteName('a.state'), $this->db->quoteName('a.featured'),
				$this->db->quoteName('a.language'), $this->db->quoteName('a.access'),
				$this->db->quoteName('a.metadesc'), $this->db->quoteName('a.metakey'), $this->db->quoteName('a.images'),
				$this->db->quoteName('a.created'), $this->db->quoteName('a.created_by'),
				$this->db->quoteName('a.created_by_alias'),
				$this->db->quoteName('u.name', 'author_name'), $this->db->quoteName('u.username', 'author_username'),
				$this->db->quoteName('a.modified'), $this->db->quoteName('a.modified_by'),
				$this->db->quoteName('a.publish_up'), $this->db->quoteName('a.publish_down'),
				$this->db->quoteName('f.featured_up'), $this->db->quoteName('f.featured_down'),
				$this->db->quoteName('a.hits'), $this->db->quoteName('a.note'),
				$this->db->quoteName('a.urls'), $this->db->quoteName('a.attribs'), $this->db->quoteName('a.metadata'),
				$this->db->quoteName('a.version'),
			])
			->from($this->db->quoteName('#__content', 'a'))
			->leftJoin(
				$this->db->quoteName('#__categories', 'c')
				. ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('a.catid')
			)
			->leftJoin(
				$this->db->quoteName('#__users', 'u')
				. ' ON ' . $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('a.created_by')
			)
			// Start/Finish Featured live in the frontpage map, not on the row.
			->leftJoin(
				$this->db->quoteName('#__content_frontpage', 'f')
				. ' ON ' . $this->db->quoteName('f.content_id') . ' = ' . $this->db->quoteName('a.id')
			)
			->where($this->db->quoteName('a.id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		// The JSON columns as objects. An empty column comes back as {} rather
		// than [] so the shape matches what update_article accepts.
		foreach (['images', 'urls', 'attribs', 'metadata'] as $column) {
			$decoded      = $row[$column] ? json_decode((string) $row[$column], true) : null;
			$row[$column] = is_array($decoded) && $decoded !== [] ? $decoded : new \stdClass();
		}
		$row['version'] = (int) $row['version'];

		// 2.9.2: the author as {id, name, username} so it can be checked after
		// update_article's created_by. name/username are null when the user
		// account no longer exists.
		$row['created_by'] = [
			'id'       => (int) $row['created_by'],
			'name'     => $row['author_name'],
			'username' => $row['author_username'],
		];
		unset($row['author_name'], $row['author_username']);

		// Tags live in #__contentitem_tag_map, not on the row. Returned so a
		// change made with update_article's `tags` argument can be checked.
		$row['tags'] = $this->itemTags('com_content.article', $id);

		return ToolResult::json($row);
	}
}
