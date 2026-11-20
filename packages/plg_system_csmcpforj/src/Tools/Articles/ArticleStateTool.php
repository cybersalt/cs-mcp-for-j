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
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * The explicit publish step (2.14.0): the Publish / Unpublish buttons of the
 * Articles list, through ArticleModel::publish(), so workflow and the
 * onContentChangeState plugins run as they do in the admin.
 *
 * Asked for so a site can let staff write drafts through MCP and leave
 * publishing to the people who have Joomla's Edit State permission. Each id is
 * checked before anything changes, and a call with one refused id changes
 * nothing: AdminModel::publish() itself would publish the others and only log
 * the refused one, which an AI app would read as "all done".
 */
abstract class ArticleStateTool extends AbstractTool
{
	use ArticleAclTrait;

	/** 1 to publish, 0 to unpublish. */
	abstract protected function targetState(): int;

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'  => ['type' => 'integer', 'description' => 'Article id.'],
				'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Several article ids. Use this OR id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$ids = [];
		if (isset($arguments['id'])) {
			$ids[] = (int) $arguments['id'];
		}
		foreach ((array) ($arguments['ids'] ?? []) as $id) {
			$ids[] = (int) $id;
		}
		$ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));

		if ($ids === []) {
			throw new McpException(-32602, 'Provide id or ids.');
		}

		$rows = $this->articleRows($ids);

		foreach ($ids as $id) {
			if (!isset($rows[$id])) {
				throw new McpException(-32602, 'Nothing was changed: article ' . $id . ' not found.');
			}
			if (!$this->canEditArticleState($actor, $id, (int) $rows[$id]['catid'])) {
				throw new McpException(-32002, 'Nothing was changed. ' . ($this->targetState() === 1 ? 'Publishing' : 'Unpublishing')
					. ' article ' . $id . ' needs Joomla\'s Edit State permission on it, which you do not have.');
			}
		}

		$model = $this->getModel('com_content', 'Article');
		$pks   = $ids;

		if (!$model->publish($pks, $this->targetState())) {
			return ToolResult::error('com_content refused the change: ' . ($model->getError() ?: 'no reason given'));
		}

		$after  = $this->articleRows($ids);
		$now    = Factory::getDate()->toSql();
		$result = [];

		foreach ($ids as $id) {
			$row   = $after[$id];
			$entry = [
				'id'             => $id,
				'title'          => $row['title'],
				'state'          => (int) $row['state'],
				'previous_state' => (int) $rows[$id]['state'],
			];

			if ((int) $row['state'] === 1 && !empty($row['publish_up']) && $row['publish_up'] > $now) {
				$entry['note'] = 'Published, but its Start Publishing date (' . $row['publish_up'] . ' UTC) is in the future, so visitors will see it from then.';
			}
			if ((int) $row['state'] === 1 && !empty($row['publish_down']) && $row['publish_down'] <= $now) {
				$entry['note'] = 'Published, but its Finish Publishing date (' . $row['publish_down'] . ' UTC) has passed, so visitors still do not see it.';
			}
			if ((int) $row['state'] !== $this->targetState()) {
				$entry['note'] = 'The state did not change to ' . $this->targetState() . '. A workflow on this site may control publishing.';
			}

			$result[] = $entry;
		}

		return ToolResult::json(['ok' => true, 'articles' => $result]);
	}

	/** @return array<int, array<string, mixed>> keyed by id */
	private function articleRows(array $ids): array
	{
		$db = $this->db;

		return (array) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['id', 'title', 'catid', 'state', 'publish_up', 'publish_down']))
				->from($db->quoteName('#__content'))
				->whereIn($db->quoteName('id'), $ids)
		)->loadAssocList('id');
	}
}
