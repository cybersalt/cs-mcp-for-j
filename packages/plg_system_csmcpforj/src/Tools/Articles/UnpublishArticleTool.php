<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

final class UnpublishArticleTool extends ArticleStateTool
{
	public function getName(): string { return 'unpublish_article'; }

	public function getDescription(): string
	{
		return 'Unpublish one or more articles: the Unpublish button of the Articles list. Needs Joomla\'s Edit State '
			. 'permission on each article; if any id is refused, nothing changes.';
	}

	protected function targetState(): int { return 0; }
}
