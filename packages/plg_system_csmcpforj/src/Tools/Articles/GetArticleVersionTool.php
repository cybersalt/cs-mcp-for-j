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

/** One stored version of an article and what differs from now (2.14.0). See ArticleHistoryTrait. */
final class GetArticleVersionTool extends AbstractTool
{
	use ArticleAclTrait;
	use ArticleHistoryTrait;

	public function getName(): string { return 'get_article_version'; }

	public function getDescription(): string
	{
		return 'Show one saved version of an article (version_id from list_article_versions): its title, text, '
			. 'images and other fields as they were, and which fields differ from the article now. Needs Edit (or Edit '
			. 'Own) on the article.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id', 'version_id'],
			'properties' => [
				'id'         => ['type' => 'integer', 'description' => 'Article id.'],
				'version_id' => ['type' => 'integer', 'description' => 'Version id from list_article_versions.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id      = $this->requirePositiveInt($arguments, 'id');
		$vid     = $this->requirePositiveInt($arguments, 'version_id');
		$article = $this->getModel('com_content', 'Article')->getItem($id);

		if (!$article || empty($article->id)) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$this->requireHistoryAccess($actor, $article);

		$version = $this->articleVersion($id, $vid);
		$row     = $version['row'];
		$fields  = [];

		foreach (array_merge(self::$restorableFields, self::$reportedOnlyFields) as $field) {
			if (!array_key_exists($field, $row)) {
				continue;
			}

			$fields[$field] = in_array($field, ['images', 'urls', 'attribs', 'metadata'], true)
				? $this->historyJson($row[$field])
				: $row[$field];
		}

		return ToolResult::json([
			'id'                  => $id,
			'version'             => $version['meta'],
			'fields'              => $fields,
			'differs_from_now'    => $this->historyDiff($row, $article),
			'restore_brings_back' => self::$restorableFields,
		]);
	}
}
