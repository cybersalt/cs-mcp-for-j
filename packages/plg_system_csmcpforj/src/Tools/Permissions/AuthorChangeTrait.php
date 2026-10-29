<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * Joomla's rule for the "Created by" field (2.10.0, field review Q6).
 *
 * Joomla's own models ignore a change of author unless the person may open
 * the Users screen: ArticleModel, CategoryModel, FieldModel and GroupModel
 * all unset the field's filter when the user lacks core.manage on com_users.
 * MCP for J refuses instead of silently ignoring, so the AI is told why.
 */
trait AuthorChangeTrait
{
	/**
	 * @param bool $isNew On create, naming yourself as the author is always
	 *                    allowed (it is what Joomla would store anyway).
	 */
	private function requireCanSetAuthor(User $actor, int $authorId, string $field, bool $isNew): void
	{
		if ($isNew && $authorId === (int) $actor->id) {
			return;
		}

		if ($actor->authorise('core.manage', 'com_users')) {
			return;
		}

		throw new \RuntimeException(
			'Permission denied: changing ' . $field . ' (the author) needs the "Access Administration Interface" '
			. 'permission on Users in Joomla, the same as in the Joomla admin, where the change would be ignored. '
			. 'Your account does not have it.'
		);
	}
}
