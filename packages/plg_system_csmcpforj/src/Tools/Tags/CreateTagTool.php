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
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

final class CreateTagTool extends AbstractTool
{
	use CategoryTagFieldsTrait;

	public function getName(): string { return 'create_tag'; }

	public function getDescription(): string
	{
		return 'Create a new tag. Required: title. parent_id defaults to 1 (root). Everything on the '
			. 'tag edit screen can be set: description, note, status, access, language, the Images tab '
			. '(images: intro and full image, float, alt, caption), the Options (params: layout, link '
			. 'class), the Publishing tab (created_user_id, created_by_alias, metadesc, metakey, metadata: '
			. 'author and robots) and a version note. Returns the new tag id.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title'],
			'properties' => [
				'title'            => ['type' => 'string'],
				'alias'            => ['type' => 'string', 'description' => 'URL alias. Generated from the title when omitted.'],
				'parent_id'        => ['type' => 'integer', 'description' => 'Parent tag id. Default 1 (root).'],
				'description'      => ['type' => 'string', 'description' => 'Tag description (HTML).'],
				'note'             => ['type' => 'string', 'description' => 'Admin-only note shown under the title in the Tags list. Up to 255 characters.'],
				'published'        => ['type' => 'integer', 'enum' => [1, 0, 2, -2], 'description' => '1 published (default), 0 unpublished, 2 archived, -2 trashed.'],
				'access'           => ['type' => 'integer', 'description' => 'Viewing access level id (see list_access_levels). Default 1 (Public).'],
				'language'         => ['type' => 'string', 'description' => 'Language code such as "en-GB", or "*" for all (default).'],
				'metadesc'         => ['type' => 'string', 'description' => 'Meta description tag for SEO (plain text; Joomla strips quotes and < >).'],
				'metakey'          => ['type' => 'string', 'description' => 'Comma-separated meta keywords.'],
				'metadata'         => $this->ctMetadataSchema(false),
				'params'           => $this->ctTagParamsSchema(false),
				'images'           => $this->ctTagImagesSchema(false),
				'created_user_id'  => ['type' => 'integer', 'description' => 'Created By: the user id shown as the tag\'s creator. Defaults to the MCP user.'],
				'created_by_alias' => ['type' => 'string', 'description' => 'Created by alias: a name shown instead of the creator\'s. Up to 255 characters.'],
				'version_note'     => ['type' => 'string', 'description' => 'A note stored with this save in the tag\'s Versions history (only kept when the site has version history on). Up to 255 characters.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title = $this->requireString($arguments, 'title');
		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		$parentId = isset($arguments['parent_id']) ? (int) $arguments['parent_id'] : 1;
		$this->ctCheckParent('#__tags', $parentId, 0, null);

		$data = [
			'id'          => 0,
			'parent_id'   => $parentId,
			'title'       => $title,
			'alias'       => $alias,
			'description' => (string) ($arguments['description'] ?? ''),
			'metadesc'    => (string) ($arguments['metadesc'] ?? ''),
			'metakey'     => (string) ($arguments['metakey'] ?? ''),
			'published'   => isset($arguments['published']) ? (int) $arguments['published'] : 1,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'params'      => $this->ctMergeJson('{}', array_key_exists('params', $arguments) ? $this->ctJsonArg($arguments['params'], 'tag_params', 'params') : []),
			'metadata'    => $this->ctMergeJson('{}', array_key_exists('metadata', $arguments) ? $this->ctJsonArg($arguments['metadata'], 'metadata', 'metadata') : []),
			'images'      => $this->ctMergeJson('{}', array_key_exists('images', $arguments) ? $this->ctJsonArg($arguments['images'], 'tag_images', 'images') : []),
			'urls'        => json_encode(new \stdClass()),
			'created_user_id' => array_key_exists('created_user_id', $arguments)
				? $this->ctUserId($arguments['created_user_id'], 'created_user_id')
				: (int) $actor->id,
		];

		if (array_key_exists('note', $arguments)) {
			$data['note'] = $this->ctShortText($arguments['note'], 'note');
		}
		if (array_key_exists('created_by_alias', $arguments)) {
			$data['created_by_alias'] = $this->ctShortText($arguments['created_by_alias'], 'created_by_alias');
		}

		$versionNote = null;
		if (array_key_exists('version_note', $arguments)) {
			$versionNote          = $this->ctShortText($arguments['version_note'], 'version_note');
			$data['version_note'] = $versionNote;
		}

		$model  = $this->getModel('com_tags', 'Tag');
		$result = $this->ctWithVersionNote($versionNote, fn (): array => $this->saveAdminModel($model, $data));

		if ($result['id'] <= 0) {
			return ToolResult::error('com_tags rejected the tag: ' . ($result['error'] ?: 'unknown error'));
		}

		$response = ['ok' => true, 'id' => $result['id'], 'title' => $title, 'alias' => $alias];
		if (!$result['ok'] && $result['error'] !== '') {
			$response['post_save_warning'] = $result['error'];
		}
		return ToolResult::json($response);
	}
}
