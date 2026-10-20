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
use Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\CategoryTagFieldsTrait;
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
 *
 * The JSON columns (params, metadata, images) are sent only when the caller
 * passes them, and then as the stored column with the caller's keys merged
 * on, read straight from #__tags: TagTable's bind json-encodes an array
 * column whole, so a bare patch would wipe every other key. `urls` is never
 * sent (no field on the 6.2 form). created_user_id and created_by_alias are
 * sent only when passed; TagTable::store() leaves both alone on an existing
 * row (reviewed against 6.2.0, 2026-10-20).
 */
final class UpdateTagTool extends AbstractTool
{
	use CategoryTagFieldsTrait;

	/** Plain columns copied through when present. */
	private const UPDATABLE = ['title', 'alias', 'description', 'metadesc', 'metakey', 'published', 'language', 'access'];

	public function getName(): string { return 'update_tag'; }

	public function getDescription(): string
	{
		return 'Update an existing tag. Required: id. Only fields you supply are changed. Everything on '
			. 'the tag edit screen can be changed: title, alias, parent, description, note, status, access, '
			. 'language, the Images tab (images: intro and full image, float, alt, caption), the Options '
			. '(params: layout, link class), the Publishing tab (created_user_id, created_by_alias, metadesc, '
			. 'metakey, metadata: author and robots) and a version note. images, params and metadata are '
			. 'merged: pass only the keys to change, null removes a key.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'               => ['type' => 'integer'],
				'title'            => ['type' => 'string'],
				'alias'            => ['type' => 'string'],
				'parent_id'        => ['type' => 'integer', 'description' => 'Move under this tag, or 1 for the top level.'],
				'description'      => ['type' => 'string', 'description' => 'Tag description (HTML).'],
				'note'             => ['type' => 'string', 'description' => 'Admin-only note shown under the title in the Tags list. Up to 255 characters. "" clears it.'],
				'published'        => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published, 0 unpublished, 2 archived, -2 trashed.'],
				'access'           => ['type' => 'integer', 'description' => 'Viewing access level id (see list_access_levels).'],
				'language'         => ['type' => 'string', 'description' => 'Language code such as "en-GB", or "*" for all.'],
				'metadesc'         => ['type' => 'string', 'description' => 'Meta description tag for SEO (plain text; Joomla strips quotes and < >).'],
				'metakey'          => ['type' => 'string', 'description' => 'Comma-separated meta keywords.'],
				'metadata'         => $this->ctMetadataSchema(true),
				'params'           => $this->ctTagParamsSchema(true),
				'images'           => $this->ctTagImagesSchema(true),
				'created_user_id'  => ['type' => 'integer', 'description' => 'Created By: the user id shown as the tag\'s creator.'],
				'created_by_alias' => ['type' => 'string', 'description' => 'Created by alias: a name shown instead of the creator\'s. Up to 255 characters. "" clears it.'],
				'version_note'     => ['type' => 'string', 'description' => 'A note stored with this save in the tag\'s Versions history (only kept when the site has version history on). Up to 255 characters.'],
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

		if (array_key_exists('parent_id', $arguments)) {
			$data['parent_id'] = (int) $arguments['parent_id'];
			$this->ctCheckParent('#__tags', $data['parent_id'], $id, null);
		}
		if (array_key_exists('note', $arguments)) {
			$data['note'] = $this->ctShortText($arguments['note'], 'note');
		}
		if (array_key_exists('created_user_id', $arguments)) {
			$data['created_user_id'] = $this->ctUserId($arguments['created_user_id'], 'created_user_id');
		}
		if (array_key_exists('created_by_alias', $arguments)) {
			$data['created_by_alias'] = $this->ctShortText($arguments['created_by_alias'], 'created_by_alias');
		}

		// JSON columns: merged onto the stored value, only when passed.
		$jsonArgs = array_intersect_key(['params' => 'tag_params', 'metadata' => 'metadata', 'images' => 'tag_images'], $arguments);
		if ($jsonArgs !== []) {
			$stored = $this->ctStoredColumns('#__tags', $id, array_keys($jsonArgs));
			foreach ($jsonArgs as $column => $kind) {
				$data[$column] = $this->ctMergeJson($stored[$column], $this->ctJsonArg($arguments[$column], $kind, $column));
			}
		}

		$versionNote = null;
		if (array_key_exists('version_note', $arguments)) {
			$versionNote          = $this->ctShortText($arguments['version_note'], 'version_note');
			$data['version_note'] = $versionNote;
		}

		$data['modified_user_id'] = (int) $actor->id;

		// TagModel::save() compares $data['parent_id'] before bind; see the
		// class docblock.
		if (!array_key_exists('parent_id', $data)) {
			$data['parent_id'] = (int) $existing->parent_id;
		}

		if (!$this->ctWithVersionNote($versionNote, fn (): bool => (bool) $model->save($data))) {
			return ToolResult::error('com_tags rejected the update: ' . $model->getError());
		}

		$changed = array_keys(array_diff_key($data, ['id' => 1, 'modified_user_id' => 1] + (array_key_exists('parent_id', $arguments) ? [] : ['parent_id' => 1])));

		return ToolResult::json(['ok' => true, 'id' => $id, 'fields_changed' => $changed]);
	}
}
