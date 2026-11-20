<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

final class PublishArticleTool extends ArticleStateTool
{
	public function getName(): string { return 'publish_article'; }

	public function getDescription(): string
	{
		return 'Publish one or more articles: the Publish button of the Articles list. Needs Joomla\'s Edit State '
			. 'permission on each article; if any id is refused, nothing changes. Use this as the explicit publish step '
			. 'after a draft was written with create_article / update_article. A future Start Publishing date still '
			. 'applies (the answer says so).';
	}

	protected function targetState(): int { return 1; }
}
