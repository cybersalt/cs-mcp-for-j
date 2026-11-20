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
use Cybersalt\Component\Csmcpforj\Administrator\Helper\MediaUploadLink;
use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/** A short-lived link to an upload page, for chat apps that can't pass files to tools (2.14.0). See MediaUploadLink. */
final class CreateMediaUploadLinkTool extends AbstractTool
{
	public function getName(): string { return 'create_media_upload_link'; }

	public function getDescription(): string
	{
		return 'Make a short-lived link the user opens in their browser to upload photos or documents into a Media '
			. 'folder (e.g. "images/news/2026"). Use it when the user attached files in a chat app that cannot pass '
			. 'files to tools (claude.ai, Claude Desktop, Claude mobile), or wants to send several at once. Give the '
			. 'user the link; the files are uploaded as them, with the same checks and photo processing as upload_media. '
			. 'When they say they are done, call list_media on the folder to find the new files (newest first). '
			. 'The link is a credential for uploads into that folder until it expires: share it only with that user.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'folder' => ['type' => 'string', 'description' => 'Folder the files go into, e.g. "images/news/2026". Default "images".'],
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
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		MediaUploadHelper::requireCreate($actor);

		$target = MediaUploadHelper::resolveFolder(MediaUploadHelper::apiModel(), (string) ($arguments['folder'] ?? 'images'));
		$link   = MediaUploadLink::create($actor, $target['display']);

		ActivityLog::info('mcp', 'media.upload_link', $actor->username . ' made an upload link for ' . $target['display']
			. ' (valid ' . $link['minutes'] . ' minutes).', ['user_id' => (int) $actor->id, 'subject' => $target['display']]);

		return ToolResult::json([
			'ok'          => true,
			'upload_url'  => $link['url'],
			'folder'      => $target['display'],
			'expires_utc' => $link['expires'],
			'valid_minutes' => $link['minutes'],
			'max_file_mb' => round(MediaUploadHelper::maxBytes() / 1048576, 1),
			'tell_user'   => 'Open this link, choose the files and press Upload. It works for ' . $link['minutes']
				. ' minutes. Then come back here and say when it is done.',
		]);
	}
}
