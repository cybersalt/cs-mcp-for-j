<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Users;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class DeleteUserTool extends AbstractTool
{
	use UsersAclTrait;

	public function getName(): string { return 'delete_user'; }

	public function getDescription(): string
	{
		return 'Delete one or more users by id. NOTE: this is permanent — Joomla does not '
			. 'have a user trash. Refuses to delete the actor themselves.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'  => ['type' => 'integer'],
				'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ids = [];
		if (isset($arguments['id'])) { $ids[] = (int) $arguments['id']; }
		if (isset($arguments['ids']) && is_array($arguments['ids'])) {
			foreach ($arguments['ids'] as $i) { $ids[] = (int) $i; }
		}
		$ids = array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
		if ($ids === []) {
			return ToolResult::error('Provide id or ids[].');
		}
		if (in_array((int) $actor->id, $ids, true)) {
			return ToolResult::error('Refusing to delete the calling user (id ' . (int) $actor->id . ').');
		}

		// Joomla's rules: Delete on Users, and only a Super User may delete a
		// Super User. Checked for every id before anything is deleted.
		$this->requireUsersRight($actor, 'core.delete', 'deleting a user');
		foreach ($ids as $targetId) {
			$this->requireCanEditTargetUser($actor, $targetId);
		}

		$model = $this->getModel('com_users', 'User');
		$idsCopy = $ids;
		if (!$model->delete($idsCopy)) {
			return ToolResult::error('Delete rejected: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'deleted' => $ids]);
	}
}
