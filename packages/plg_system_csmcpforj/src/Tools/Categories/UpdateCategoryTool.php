<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * @partial-save-safe CategoryModel::save() binds only supplied keys.
 * Verified behaviourally on Joomla 6.1.4, 2026-10-09: created a category
 * with every field populated, changed only `title`, re-read and diffed all
 * 27 fields — nothing else moved. Re-read against Joomla 6.2.0, 2026-10-20:
 * save() now goes straight to AdminModel::save() (bind, then prepareTable()
 * compares the bound parent_id with the loaded row's), and reads $data only
 * on a save2copy task, which an MCP request never carries.
 *
 * Tags are the exception that check could not see: they live in
 * #__contentitem_tag_map and Joomla's Taggable behaviour deletes them when
 * `tags` is absent from the save (2.9.0, found via update_article). The tool
 * now always sends tags; see AbstractTool::resolveTags().
 *
 * The JSON columns (params, metadata) are sent only when the caller passes
 * them, and then as the stored column with the caller's keys merged on, read
 * straight from #__categories. Category::bind() replaces a JSON column
 * whole, so sending a bare patch would wipe every other key.
 *
 * created_time and created_user_id are sent only when passed; on an existing
 * row Category::store() leaves both alone and overwrites modified_*.
 */
final class UpdateCategoryTool extends AbstractTool
{
	use CategoryTagFieldsTrait;

	/** Plain columns copied through when present. */
	private const UPDATABLE = ['title', 'alias', 'description', 'metadesc', 'metakey', 'published', 'language', 'access'];

	public function getName(): string { return 'update_category'; }

	public function getDescription(): string
	{
		return 'Update an existing category. Required: id. Only fields you supply are changed, '
			. 'including tags. Everything on the category edit screen can be changed: title, alias, '
			. 'parent, description, note, status, access, language, tags, the Options (params: layout, '
			. 'image, image alt text, workflow), the Publishing tab (created_user_id, created_time, '
			. 'metadesc, metakey, metadata: author and robots) and a version note. params and metadata '
			. 'are merged: pass only the keys to change, null removes a key. Pass tags (an array of tag ids) '
			. 'to replace the tags on the category; an empty array removes them all. '
			. 'The extension a category belongs to cannot be changed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id'              => ['type' => 'integer'],
				'title'           => ['type' => 'string'],
				'alias'           => ['type' => 'string'],
				'parent_id'       => ['type' => 'integer', 'description' => 'Move under this category (same extension), or 1 for the top level.'],
				'description'     => ['type' => 'string', 'description' => 'On-page category description (HTML).'],
				'note'            => ['type' => 'string', 'description' => 'Admin-only note shown under the title in the Categories list. Up to 255 characters. "" clears it.'],
				'published'       => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published, 0 unpublished, 2 archived, -2 trashed.'],
				'access'          => ['type' => 'integer', 'description' => 'Viewing access level id (see list_access_levels).'],
				'language'        => ['type' => 'string', 'description' => 'Language code such as "en-GB", or "*" for all.'],
				'tags'            => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tag ids. Replaces the tags on the category; [] removes them all. Omit to keep the current tags.'],
				'metadesc'        => ['type' => 'string', 'description' => 'Meta description tag for SEO (plain text, no HTML).'],
				'metakey'         => ['type' => 'string', 'description' => 'Comma-separated meta keywords.'],
				'metadata'        => $this->ctMetadataSchema(true),
				'params'          => $this->ctCategoryParamsSchema(true),
				'created_user_id' => ['type' => 'integer', 'description' => 'Created By: the user id shown as the category\'s creator.'],
				'created_time'    => $this->ctCreatedTimeSchema(true),
				'version_note'    => ['type' => 'string', 'description' => 'A note stored with this save in the category\'s Versions history (only kept when the site has version history on). Up to 255 characters.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id    = $this->requirePositiveInt($arguments, 'id');
		$model = $this->getModel('com_categories', 'Category');

		$existing = $model->getItem($id);
		if (!$existing || empty($existing->id)) {
			return ToolResult::error('Category ' . $id . ' not found.');
		}

		$model->setState($model->getName() . '.extension', $existing->extension);

		$data = ['id' => $id];
		foreach (self::UPDATABLE as $key) {
			if (array_key_exists($key, $arguments)) {
				$data[$key] = $arguments[$key];
			}
		}

		if (array_key_exists('parent_id', $arguments)) {
			$data['parent_id'] = (int) $arguments['parent_id'];
			$this->ctCheckParent('#__categories', $data['parent_id'], $id, (string) $existing->extension);
		}
		if (array_key_exists('note', $arguments)) {
			$data['note'] = $this->ctShortText($arguments['note'], 'note');
		}
		if (array_key_exists('created_user_id', $arguments)) {
			$data['created_user_id'] = $this->ctUserId($arguments['created_user_id'], 'created_user_id');
		}
		if (array_key_exists('created_time', $arguments)) {
			$data['created_time'] = $this->ctDate($arguments['created_time'], 'created_time');
		}

		// JSON columns: merged onto the stored value, only when passed.
		$jsonArgs = array_intersect_key(['params' => 'cat_params', 'metadata' => 'metadata'], $arguments);
		if ($jsonArgs !== []) {
			$stored = $this->ctStoredColumns('#__categories', $id, array_keys($jsonArgs));
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

		// Always send tags, or Joomla's Taggable behaviour deletes them. The
		// type alias follows the category's extension (com_content.category,
		// com_contact.category, ...).
		$data['tags'] = $this->resolveTags($arguments, $existing->extension . '.category', $id);

		if (!$this->ctWithVersionNote($versionNote, fn (): bool => (bool) $model->save($data))) {
			return ToolResult::error('com_categories rejected the update: ' . $model->getError());
		}

		$changed = array_keys(array_diff_key(
			$data,
			['id' => 1, 'modified_user_id' => 1] + (array_key_exists('tags', $arguments) ? [] : ['tags' => 1])
		));

		return ToolResult::json(['ok' => true, 'id' => $id, 'fields_changed' => $changed]);
	}
}
