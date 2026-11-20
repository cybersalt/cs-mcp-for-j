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
use Joomla\Filesystem\File;

/** The Media Manager's "Create New Folder" (2.14.0), through com_media's ApiModel. */
final class CreateMediaFolderTool extends AbstractTool
{
	public function getName(): string { return 'create_media_folder'; }

	public function getDescription(): string
	{
		return 'Create a folder in the Media Manager, e.g. name "2026-11" in folder "images/news". Needs Joomla\'s Create '
			. 'permission in Media. (upload_media also creates missing folders on its own.)';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'required'   => ['name'],
			'properties' => [
				'folder' => ['type' => 'string', 'description' => 'Parent folder, e.g. "images/news". Default "images".'],
				'name'   => ['type' => 'string', 'description' => 'New folder name (letters, digits, hyphen, underscore).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		MediaUploadHelper::requireCreate($actor);

		$name = trim((string) ($arguments['name'] ?? ''));
		$safe = File::makeSafe(preg_replace('/\s+/u', '-', $name));

		if ($safe === '' || $safe !== $name || str_contains($name, '.')) {
			throw new McpException(-32602, 'Folder name "' . $name . '" is not usable. Use letters, digits, hyphens and underscores'
				. ($safe !== '' && $safe !== $name && !str_contains($safe, '.') ? ', e.g. "' . $safe . '"' : '') . '.');
		}

		$model  = MediaUploadHelper::apiModel();
		$target = MediaUploadHelper::resolveFolder($model, (string) ($arguments['folder'] ?? 'images'));

		try {
			$created = $model->createFolder($target['adapter'], $name, $target['path'], false);
		} catch (\Joomla\Component\Media\Administrator\Exception\FileExistsException $e) {
			return ToolResult::json(['ok' => true, 'path' => $target['display'] . '/' . $name, 'already_existed' => true]);
		}

		return ToolResult::json(['ok' => true, 'path' => $target['display'] . '/' . $created, 'already_existed' => false]);
	}
}
