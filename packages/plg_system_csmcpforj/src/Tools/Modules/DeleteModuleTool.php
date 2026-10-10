<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class DeleteModuleTool extends AbstractTool
{
	public function getName(): string { return 'delete_module'; }

	public function getDescription(): string { return 'Trash or permanently delete module(s). Default: trash.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id'        => ['type' => 'integer'],
				'ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
				'permanent' => ['type' => 'boolean'],
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

		$model = $this->getModel('com_modules', 'Module');

		if (!empty($arguments['permanent'])) {
			/*
			 * ModuleModel::delete() refuses anything not already trashed:
			 *
			 *     if (!$user->authorise('core.delete', ...) || $table->published != -2) {
			 *         ...enqueueMessage(JERROR_CORE_DELETE_NOT_PERMITTED, 'error');
			 *         return;
			 *     }
			 *
			 * Note it `return;`s — null, with NOTHING in $model->getError() — and
			 * pushes the reason onto the APPLICATION MESSAGE QUEUE instead. So the
			 * old `'Permanent delete rejected: ' . $model->getError()` produced a
			 * bare prefix and no reason, which told the caller nothing at all.
			 *
			 * Check the precondition up front so the error can name it. We
			 * deliberately do NOT auto-trash first: the same condition also covers
			 * the permission check, so a silent trash-then-delete would leave the
			 * module trashed whenever the real problem was authorisation — a
			 * partial state change caused by a call that reported failure.
			 */
			$notTrashed = $this->modulesNotTrashed($ids);

			if ($notTrashed !== []) {
				return ToolResult::error(
					'Permanent delete refused: module(s) ' . implode(', ', $notTrashed) . ' are not in the '
					. 'trash, and Joomla only permanently deletes trashed modules. Call this tool without '
					. '"permanent" first to trash them, then call it again with "permanent": true. '
					. '(Doing both steps automatically is deliberately avoided: the same core check also '
					. 'covers delete permission, so an automatic trash would leave the module trashed on a '
					. 'call that failed for an entirely different reason.)'
				);
			}

			$idsCopy = $ids;

			if (!$model->delete($idsCopy)) {
				return ToolResult::error('Permanent delete rejected: ' . $this->modelFailureReason($model));
			}

			return ToolResult::json(['ok' => true, 'deleted' => $ids, 'permanent' => true]);
		}

		$idsCopy = $ids;
		if (!$model->publish($idsCopy, -2)) {
			return ToolResult::error('Trash rejected: ' . $this->modelFailureReason($model));
		}
		return ToolResult::json(['ok' => true, 'trashed' => $ids, 'permanent' => false]);
	}

	/**
	 * Which of these module ids are NOT in the trash (published != -2).
	 *
	 * @return int[]
	 */
	private function modulesNotTrashed(array $ids): array
	{
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'published']))
			->from($this->db->quoteName('#__modules'))
			->whereIn($this->db->quoteName('id'), $ids);

		$out = [];

		foreach (($this->db->setQuery($q)->loadAssocList() ?: []) as $row) {
			if ((int) $row['published'] !== -2) {
				$out[] = (int) $row['id'];
			}
		}

		return $out;
	}

	/**
	 * Why did a core model call fail?
	 *
	 * getError() is the obvious place and is frequently empty: several core
	 * models push the reason onto the application message queue and simply
	 * `return;`, leaving getError() untouched. Reading only getError() yields an
	 * error string with no reason in it, which is worse than useless — it tells
	 * the caller something went wrong while withholding the one fact they need.
	 *
	 * So: prefer getError(), fall back to whatever the model enqueued, and say
	 * plainly when neither channel produced anything.
	 */
	private function modelFailureReason(object $model): string
	{
		$error = method_exists($model, 'getError') ? trim((string) $model->getError()) : '';

		if ($error !== '') {
			return $error;
		}

		$queued = [];

		foreach ((array) Factory::getApplication()->getMessageQueue() as $message) {
			$text = trim((string) ($message['message'] ?? ''));

			if ($text !== '' && in_array(($message['type'] ?? ''), ['error', 'warning'], true)) {
				$queued[] = $text;
			}
		}

		if ($queued !== []) {
			return implode(' ', array_unique($queued));
		}

		return 'the model refused without reporting a reason (nothing in getError() and nothing enqueued).';
	}

}
