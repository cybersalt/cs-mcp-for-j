<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Media;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\MediaUploadHelper;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * The Media Manager's folder view as a tool (2.14.0). Through com_media's
 * ApiModel, so it lists what the Media Manager lists: only allowed file
 * types, from the folders the FileSystem - Local plugin offers.
 */
final class ListMediaTool extends AbstractTool
{
	public function getName(): string { return 'list_media'; }

	public function getDescription(): string
	{
		return 'List the folders and files in a Media Manager folder (images/, files/ and any other folder the site '
			. 'offers), with each file\'s path for article fields (e.g. images/news/photo.jpg), public URL, size, '
			. 'type, image width/height and modified date (UTC). Newest first. Optional search matches file names, '
			. 'in subfolders too. Omit folder to see the top-level Media folders. Needs Joomla\'s Media permissions.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'folder' => ['type' => 'string', 'description' => 'Folder, e.g. "images", "images/news/2026" or "files". Default "images".'],
				'search' => ['type' => 'string', 'description' => 'Only names containing this text (searches subfolders too).'],
				'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Most entries to return. Default 100.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		MediaUploadHelper::requireBrowse($actor);

		$model  = MediaUploadHelper::apiModel();
		$roots  = array_map(static fn (array $a): string => $a['root'], MediaUploadHelper::adapters($model));
		$target = MediaUploadHelper::resolveFolder($model, (string) ($arguments['folder'] ?? 'images'));
		$search = trim((string) ($arguments['search'] ?? ''));
		$limit  = max(1, min(500, (int) ($arguments['limit'] ?? 100)));

		if ($search !== '' && preg_match('/[^\p{L}\p{N}_.\- ]/u', $search)) {
			throw new McpException(-32602, 'search may contain letters, digits, spaces, dot, hyphen and underscore only.');
		}

		try {
			$items = $model->getFiles($target['adapter'], $target['path'], [
				'url'       => false,
				'content'   => false,
				'search'    => $search !== '' ? $search : null,
				'recursive' => true,
			]);
		} catch (\Joomla\Component\Media\Administrator\Exception\FileNotFoundException $e) {
			return ToolResult::error('The folder ' . $target['display'] . ' does not exist. Media folders here: ' . implode(', ', $roots) . '.');
		}

		$root    = MediaUploadHelper::siteRoot();
		$folders = [];
		$files   = [];

		foreach ($items as $item) {
			// "local-images:/news/photo.jpg" → "images/news/photo.jpg". Search
			// results come from subfolders, so always build from the item's path.
			$inner    = (string) preg_replace('/^[^:]+:/', '', (string) $item->path);
			$relative = rtrim($target['root'] . $inner, '/');

			if ($item->type === 'dir') {
				$folders[] = ['name' => (string) $item->name, 'path' => $relative];
				continue;
			}

			$entry = [
				'name'     => (string) $item->name,
				'path'     => $relative,
				'url'      => $root . '/' . implode('/', array_map('rawurlencode', explode('/', $relative))),
				'size'     => (int) ($item->size ?? 0),
				'mime'     => (string) ($item->mime_type ?? ''),
				'modified' => (string) ($item->modified_date ?? ''),
			];
			if (!empty($item->width)) {
				$entry['width']  = (int) $item->width;
				$entry['height'] = (int) $item->height;
			}
			$files[] = $entry;
		}

		usort($files, static fn (array $a, array $b): int => strcmp($b['modified'], $a['modified']));
		usort($folders, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		$total = count($files);

		return ToolResult::json([
			'folder'      => $target['display'],
			'media_roots' => $roots,
			'folders'     => $folders,
			'files'       => array_slice($files, 0, $limit),
			'file_count'  => $total,
			'truncated'   => $total > $limit,
		]);
	}
}
