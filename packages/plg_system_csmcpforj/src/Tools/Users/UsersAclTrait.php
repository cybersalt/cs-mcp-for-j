<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

/**
 * Joomla's own Users permissions, applied to the user tools (2.10.0).
 *
 * The tools save through com_users' models, but the permission checks live in
 * Joomla's controllers (UserController::allowEdit, allowAdd and the list
 * actions), which MCP never goes through. Before 2.10.0 the MCP write grant
 * alone was enough to create users, edit them and put them in any group except
 * Super Users, even for an account that cannot open the Users screen.
 *
 * Tim, 2026-10-28: "they should only have access to do what they can as their
 * user." So each tool also needs the matching right on com_users, exactly as
 * the Users screen would ask for it.
 */
trait UsersAclTrait
{
	private function requireUsersRight(User $actor, string $action, string $doing): void
	{
		// Joomla's com_users dispatcher refuses anyone without core.manage
		// before any action check, so Create / Edit / Delete alone are not
		// enough. They matter: Joomla's default root rules give core.create to
		// Authors and above site-wide, which com_users inherits.
		if ($action !== 'core.manage' && !$actor->authorise('core.manage', 'com_users')) {
			$action = 'core.manage';
		} elseif ($actor->authorise($action, 'com_users')) {
			return;
		}

		$labels = [
			'core.manage'     => 'Access Administration Interface',
			'core.create'     => 'Create',
			'core.edit'       => 'Edit',
			'core.edit.state' => 'Edit State',
			'core.delete'     => 'Delete',
		];

		throw new \RuntimeException(
			'Permission denied: ' . $doing . ' needs the "' . ($labels[$action] ?? $action)
			. '" permission on Users in Joomla (Users > Options > Permissions), the same as in the Joomla admin. '
			. 'Your account does not have it.'
		);
	}

	/**
	 * Acting on someone else's account: the com_users right, plus Joomla's
	 * rule that only a Super User may act on a Super User. Your own account
	 * needs neither, as in Joomla (UserController::allowEdit).
	 */
	private function requireCanActOnUser(User $actor, int $targetId, string $action, string $doing): void
	{
		if ((int) $actor->id === $targetId) {
			return;
		}

		$this->requireUsersRight($actor, $action, $doing);
		$this->requireCanEditTargetUser($actor, $targetId);
	}
}
