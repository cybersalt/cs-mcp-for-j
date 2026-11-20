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
 * The Media Manager's Delete (2.14.0), one file or one EMPTY folder.
 *
 * Joomla's own delete removes a folder with everything in it. There is no
 * trash in Media, so from an AI app that is one misread instruction away from
 * losing a site's images: a folder must be empty first. A file still in use by
 * an article is not checked (Joomla doesn't either); the description says so.
 */
final class DeleteMediaTool extends AbstractTool
{
	public function getName(): string { return 'delete_media'; }

	public function getDescription(): string
	{
		return 'Delete one file, or one empty folder, from the Media Manager (e.g. "images/news/old-photo.jpg"). '
			. 'There is no trash: it is gone. Articles that still show the file are not checked, so check first. '
			. 'Folders must be empty. Needs Joomla\'s Delete permission in Media.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['path'],
			'properties' => [
				'path' => ['type' => 'string', 'description' => 'File or folder, e.g. "images/news/photo.jpg".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		MediaUploadHelper::requireDelete($actor);

		$path   = trim((string) ($arguments['path'] ?? ''), " /");
		$model  = MediaUploadHelper::apiModel();
		$target = MediaUploadHelper::resolveFolder($model, $path);

		if ($target['path'] === '/') {
			throw new McpException(-32602, 'A top-level Media folder (' . $target['display'] . ') cannot be deleted.');
		}

		try {
			$item = $model->getFile($target['adapter'], $target['path']);
		} catch (\Joomla\Component\Media\Administrator\Exception\FileNotFoundException $e) {
			return ToolResult::error($target['display'] . ' does not exist.');
		} catch (\Joomla\Component\Media\Administrator\Exception\InvalidPathException $e) {
			return ToolResult::error($target['display'] . ' is not a file type the Media Manager handles.');
		}

		if ($item->type === 'dir') {
			// The adapter's own listing, not ApiModel::getFiles(): that one hides
			// file types Media doesn't show, and Joomla's delete would take them too.
			$inside = $model->getAdapter($target['adapter'])->getFiles($target['path']);

			if ($inside !== []) {
				throw new McpException(-32602, 'Nothing was deleted: the folder ' . $target['display'] . ' is not empty ('
					. count($inside) . ' item(s)). Delete what is inside first.');
			}
		}

		$model->delete($target['adapter'], $target['path']);

		return ToolResult::json(['ok' => true, 'deleted' => $target['display'], 'type' => $item->type === 'dir' ? 'folder' : 'file']);
	}
}
