<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * @partial-save-safe TagModel::save() binds only supplied keys. Verified
 * behaviourally on Joomla 6.1.4, 2026-10-09: created a tag with every field
 * populated, changed only `title`, diffed all 30 fields — nothing else
 * moved. Note tags are a nested set like menus, so this was NOT assumed from
 * #__menu behaviour.
 *
 * It is safe partly by luck: TagModel::save() reads $data['parent_id']
 * before bind (`if ($table->parent_id != $data['parent_id'] ...)
 * setLocation($data['parent_id'], 'last-child')`). An absent key queues a
 * move to location null, which Nested::store() only skips because null > 0
 * is false — and the read raises an undefined-key warning that a site with
 * display_errors on prints into the JSON response. parent_id is re-supplied
 * from the row so the model sees "same parent" (reviewed against Joomla
 * 6.2.0, 2026-10-19).
 */
final class UpdateTagTool extends AbstractTool
{
	private const UPDATABLE = ['title', 'alias', 'parent_id', 'description', 'published', 'language', 'access'];

	public function getName(): string { return 'update_tag'; }

	public function getDescription(): string { return 'Update an existing tag. Required: id.'; }

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'          => ['type' => 'integer'],
				'title'       => ['type' => 'string'],
				'alias'       => ['type' => 'string'],
				'parent_id'   => ['type' => 'integer'],
				'description' => ['type' => 'string'],
				'published'   => ['type' => 'integer', 'enum' => [0, 1]],
				'language'    => ['type' => 'string'],
				'access'      => ['type' => 'integer'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_tags', 'Tag');
		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Tag ' . $id . ' not found.');
		}

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}
		$data['modified_user_id'] = (int) $actor->id;

		// TagModel::save() compares $data['parent_id'] before bind; see the
		// class docblock.
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing->parent_id;
		}

		if (!$model->save($data)) {
			return ToolResult::error('com_tags rejected the update: ' . $model->getError());
		}
		return ToolResult::json(['ok' => true, 'id' => $id]);
	}
}
