<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\User\User;

/**
 * Joomla's own article permissions, for the article tools (#45, articles part).
 *
 * Before 2.14.0 the article tools only checked MCP for J's Write permission,
 * so an Editor or Author given MCP write could publish, and edit any article.
 * ArticleModel::save() does not check these itself: the admin and front-end
 * controllers do (allowAdd / allowEdit), and the publish fields are removed by
 * the FORM filter, neither of which runs when a tool calls save(). So each
 * rule below mirrors the Joomla code that would have applied:
 *
 *   - create:     core.create on the category  (ArticleController::allowAdd)
 *   - edit:       core.edit on the article, or core.edit.own when you are the
 *                 author                       (ArticleController::allowEdit)
 *   - publishing: core.edit.state on the article, or on the category for a new
 *                 one                          (ArticleModel::canEditState).
 *                 Covers state, featured and the publish / featured dates, the
 *                 fields ArticleModel::getForm() disables without it.
 *   - move:       core.create on the new category (#45).
 *   - delete:     core.delete on the article   (ArticleModel::canDelete)
 *
 * core.manage on com_content is deliberately NOT required: Joomla lets an
 * Author or Editor without it write articles from the front end, and that is
 * exactly the staff member these rules are for.
 *
 * Kept in a trait in the plugin, not on AbstractTool, because add-ons extend
 * AbstractTool and a new protected method there can clash with one of theirs.
 */
trait ArticleAclTrait
{
	/** Fields that only someone with Edit State may set (ArticleModel::getForm()). */
	private static array $publishingFields = ['state', 'featured', 'publish_up', 'publish_down', 'featured_up', 'featured_down'];

	private function requireArticleCreate(User $actor, int $catid): void
	{
		if (!$actor->authorise('core.create', 'com_content.category.' . $catid)) {
			throw new McpException(-32002, 'Permission denied: you may not create articles in category ' . $catid
				. '. That needs Joomla\'s Create permission on the category, the same as in the article editor.');
		}
	}

	private function requireArticleEdit(User $actor, object $article): void
	{
		$id = (int) $article->id;

		if ($actor->authorise('core.edit', 'com_content.article.' . $id)) {
			return;
		}

		if ($actor->authorise('core.edit.own', 'com_content.article.' . $id) && (int) $article->created_by === (int) $actor->id) {
			return;
		}

		throw new McpException(-32002, 'Permission denied: you may not edit article ' . $id
			. '. That needs Joomla\'s Edit permission, or Edit Own on an article you wrote.');
	}

	/** ArticleModel::canEditState(): the article for an existing one, else its category. */
	private function canEditArticleState(User $actor, int $id, int $catid): bool
	{
		if ($id > 0) {
			return $actor->authorise('core.edit.state', 'com_content.article.' . $id);
		}

		if ($catid > 0) {
			return $actor->authorise('core.edit.state', 'com_content.category.' . $catid);
		}

		return $actor->authorise('core.edit.state', 'com_content');
	}

	/**
	 * Refuse a create or update that sets a publishing field without Edit State.
	 * Nothing is saved: dropping the field quietly would read as "published"
	 * to the AI app.
	 *
	 * $asked are the argument keys the caller sent. On create, state 0 and
	 * featured 0 are what a draft gets anyway, so asking for them explicitly is
	 * allowed.
	 */
	private function requireArticleEditStateFor(User $actor, int $id, int $catid, array $arguments, bool $isNew): void
	{
		$asked = array_values(array_intersect(self::$publishingFields, array_keys($arguments)));

		if ($isNew) {
			$asked = array_values(array_filter($asked, static function (string $key) use ($arguments): bool {
				$value = $arguments[$key];

				return !(in_array($key, ['state', 'featured'], true) && (int) $value === 0)
					&& !(in_array($key, ['publish_up', 'publish_down', 'featured_up', 'featured_down'], true) && ($value === null || $value === ''));
			}));
		}

		if ($asked === [] || $this->canEditArticleState($actor, $id, $catid)) {
			return;
		}

		throw new McpException(-32002, 'Nothing was saved. Changing ' . implode(', ', $asked) . ' needs Joomla\'s Edit State permission on '
			. ($id > 0 ? 'article ' . $id : 'category ' . $catid) . ', which you do not have. '
			. ($isNew ? 'Leave those out and the article is saved as an unpublished draft' : 'Leave those out to save the other changes')
			. '; someone with Edit State can then publish it (publish_article).');
	}

	private function requireArticleDelete(User $actor, int $id): void
	{
		if (!$actor->authorise('core.delete', 'com_content.article.' . $id)) {
			throw new McpException(-32002, 'Permission denied: you may not permanently delete article ' . $id
				. '. That needs Joomla\'s Delete permission.');
		}
	}
}
