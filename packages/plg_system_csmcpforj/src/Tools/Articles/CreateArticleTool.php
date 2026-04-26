<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\User\User;

final class CreateArticleTool extends AbstractTool
{
	public function getName(): string { return 'create_article'; }

	public function getDescription(): string
	{
		return 'Create a new article in com_content. Required: title, catid, articletext. '
			. 'Use list_categories to find a valid catid. Returns the new article id and edit URL.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['title', 'catid', 'articletext'],
			'properties' => [
				'title'              => ['type' => 'string', 'description' => 'Article title.'],
				'alias'              => ['type' => 'string', 'description' => 'URL alias. Auto-generated from title if omitted.'],
				'catid'              => ['type' => 'integer', 'description' => 'Category id. Use list_categories.'],
				'articletext'        => ['type' => 'string', 'description' => 'Full HTML body. <hr id="system-readmore"> splits intro/full text.'],
				'state'              => ['type' => 'integer', 'enum' => [0, 1, 2, -2], 'description' => '0=unpublished, 1=published, 2=archived, -2=trashed.'],
				'language'           => ['type' => 'string', 'description' => 'Language tag, e.g. "en-GB" or "*".'],
				'access'             => ['type' => 'integer', 'description' => 'Viewing access level id (1=Public).'],
				'metadesc'           => ['type' => 'string'],
				'metakey'            => ['type' => 'string'],
				'image_intro'        => ['type' => 'string'],
				'image_intro_alt'    => ['type' => 'string'],
				'image_fulltext'     => ['type' => 'string'],
				'image_fulltext_alt' => ['type' => 'string'],
				'featured'           => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$title = $this->requireString($arguments, 'title');
		$catid = $this->requirePositiveInt($arguments, 'catid');
		$body  = $this->requireString($arguments, 'articletext');

		$alias = (string) ($arguments['alias'] ?? '');
		if ($alias === '') {
			$alias = OutputFilter::stringURLSafe($title);
		}

		$images = [
			'image_intro'            => (string) ($arguments['image_intro'] ?? ''),
			'image_intro_alt'        => (string) ($arguments['image_intro_alt'] ?? ''),
			'float_intro'            => '',
			'image_intro_caption'    => '',
			'image_fulltext'         => (string) ($arguments['image_fulltext'] ?? ''),
			'image_fulltext_alt'     => (string) ($arguments['image_fulltext_alt'] ?? ''),
			'float_fulltext'         => '',
			'image_fulltext_caption' => '',
		];

		$data = [
			'id'          => 0,
			'title'       => $title,
			'alias'       => $alias,
			'catid'       => $catid,
			'articletext' => $body,
			'state'       => isset($arguments['state']) ? (int) $arguments['state'] : 0,
			'language'    => (string) ($arguments['language'] ?? '*'),
			'access'      => isset($arguments['access']) ? (int) $arguments['access'] : 1,
			'featured'    => isset($arguments['featured']) ? (int) $arguments['featured'] : 0,
			'metadesc'    => (string) ($arguments['metadesc'] ?? ''),
			'metakey'     => (string) ($arguments['metakey'] ?? ''),
			'images'      => json_encode($images),
			'urls'        => json_encode(new \stdClass()),
			'attribs'     => json_encode(new \stdClass()),
			'metadata'    => json_encode(new \stdClass()),
			'created_by'  => (int) $actor->id,
		];

		$model = $this->getModel('com_content', 'Article');
		if (!$model->save($data)) {
			return ToolResult::error('com_content rejected the article: ' . $model->getError());
		}

		$id = (int) $model->getState($model->getName() . '.id');
		return ToolResult::json([
			'ok'       => true,
			'id'       => $id,
			'title'    => $title,
			'alias'    => $alias,
			'catid'    => $catid,
			'state'    => $data['state'],
			'edit_url' => 'index.php?option=com_content&task=article.edit&id=' . $id,
		]);
	}
}
