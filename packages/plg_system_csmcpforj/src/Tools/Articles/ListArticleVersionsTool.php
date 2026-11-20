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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/** The editor's Versions button as a tool (2.14.0). See ArticleHistoryTrait. */
final class ListArticleVersionsTool extends AbstractTool
{
	use ArticleAclTrait;
	use ArticleHistoryTrait;

	public function getName(): string { return 'list_article_versions'; }

	public function getDescription(): string
	{
		return 'List the saved versions of an article (its version history, the Versions button in the editor), '
			. 'newest first: version_id, save date (UTC), who saved it, version note and size. Use get_article_version '
			. 'to see one and what differs from now, and restore_article_version to bring one back. Needs Edit (or Edit '
			. 'Own) on the article. Joomla keeps as many versions as com_content\'s "Maximum Versions" option allows.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Article id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id      = $this->requirePositiveInt($arguments, 'id');
		$article = $this->getModel('com_content', 'Article')->getItem($id);

		if (!$article || empty($article->id)) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$this->requireHistoryAccess($actor, $article);

		$versions = $this->articleVersions($id);
		$response = [
			'id'           => $id,
			'title'        => (string) $article->title,
			'versions'     => $versions,
			'count'        => count($versions),
			'history_on'   => $this->historyEnabled(),
			'max_versions' => (int) ComponentHelper::getParams('com_content')->get('history_limit', 0),
		];

		if (!$response['history_on']) {
			$response['note'] = 'Version history is switched off for articles (Articles → Options → Editing Layout → Enable Versions), '
				. 'so new saves are not kept.';
		}

		return ToolResult::json($response);
	}
}
