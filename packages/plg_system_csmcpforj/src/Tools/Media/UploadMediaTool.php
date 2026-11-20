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
use Cybersalt\Component\Csmcpforj\Administrator\Helper\SafeFetch;
use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Upload a file into the Media Manager (2.14.0). See MediaUploadHelper for the
 * checks and ImageProcessor for what happens to photos.
 *
 * The file can arrive three ways, because AI apps differ:
 *   - `file`: a file the user attached in ChatGPT. The tool declares it in
 *     `_meta["openai/fileParams"]`, and ChatGPT then sends an object with a
 *     short-lived `download_url` (and `file_id`) instead of the bytes. We
 *     download it with SafeFetch. ⚠️ Not confirmed for custom connectors in
 *     ChatGPT's developer mode (reports say attachments aren't passed there,
 *     2026-11), so the upload link is the route that works everywhere.
 *   - `url`: any public https address (SafeFetch's SSRF rules apply).
 *   - `content_base64`: the bytes themselves, for apps that can read the file
 *     (Claude Code, Claude Desktop with file access, scripts).
 * Claude's chat apps can't hand an attached file to an MCP server, so for them
 * there is create_media_upload_link: a page where the person picks the files
 * in their browser.
 */
final class UploadMediaTool extends AbstractTool
{
	public function getName(): string { return 'upload_media'; }

	public function getDescription(): string
	{
		return 'Upload an image or document (photo, PDF...) into the Media Manager, e.g. folder "images/news/2026". '
			. 'Give the file ONE way: file (a file the user attached in ChatGPT), url (a public https address), or '
			. 'content_base64 with filename. Photos are processed as this site\'s Options say (turned upright, '
			. 'resized, EXIF and GPS data removed, optionally WebP); the answer says what was done. Joomla\'s Media '
			. 'settings decide which file types and sizes are allowed. Returns the path to use in an article\'s '
			. 'image fields (e.g. images/news/2026/photo.jpg) and the public URL. If the user attached a file in a '
			. 'chat app that cannot pass files to tools (claude.ai, Claude Desktop, Claude mobile, and ChatGPT when no '
			. 'file object arrives), use '
			. 'create_media_upload_link instead. Needs Joomla\'s Create permission in Media.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'folder'         => ['type' => 'string', 'description' => 'Target folder, e.g. "images/news/2026" or "files/minutes". Missing folders are created. Default "images".'],
				'filename'       => ['type' => 'string', 'description' => 'File name with extension, e.g. "town-hall-opening.jpg". Required with content_base64; otherwise optional (taken from the attachment or URL).'],
				'file'           => [
					'type'        => 'object',
					'description' => 'A file the user attached in ChatGPT (ChatGPT fills this in).',
					// The Apps SDK reference's shape: all four declared (Scan Tools rejects a
					// schema missing any), only these two always sent.
					'properties'  => [
						'download_url' => ['type' => 'string'],
						'file_id'      => ['type' => 'string'],
						'mime_type'    => ['type' => 'string'],
						'file_name'    => ['type' => 'string'],
					],
					'required'    => ['download_url', 'file_id'],
					'additionalProperties' => false,
				],
				'url'            => ['type' => 'string', 'description' => 'A public https address to download the file from.'],
				'content_base64' => ['type' => 'string', 'description' => 'The file\'s bytes, base64-encoded.'],
				'overwrite'      => ['type' => 'boolean', 'description' => 'Replace a file with the same name. Default false.'],
			],
			'additionalProperties' => false,
		];
	}

	/** ChatGPT Apps SDK: `file` carries an attachment, not text. */
	public function getMcpMeta(): array
	{
		return ['openai/fileParams' => ['file']];
	}

	public function getRequiredPermission(): string { return 'write'; }

	public function getMcpAnnotations(): array
	{
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => true,
		];
	}

	protected function run(array $arguments, User $actor): ToolResult
	{
		$this->refuseUnknownArguments($arguments);
		MediaUploadHelper::requireCreate($actor);

		$given = array_values(array_intersect(['file', 'url', 'content_base64'], array_keys(array_filter(
			$arguments,
			static fn ($v): bool => $v !== null && $v !== '' && $v !== []
		))));

		if (count($given) !== 1) {
			throw new McpException(-32602, $given === []
				? 'Give the file as file (a ChatGPT attachment), url or content_base64. In claude.ai or Claude Desktop, where '
					. 'attachments can\'t reach tools, call create_media_upload_link and give the user the link.'
				: 'Give the file ONE way; got ' . implode(', ', $given) . '.');
		}

		$max      = MediaUploadHelper::maxBytes();
		$filename = trim((string) ($arguments['filename'] ?? ''));
		$source   = $given[0];

		if ($source === 'content_base64') {
			$encoded = preg_replace('/^data:[^,]*,/', '', (string) $arguments['content_base64']);
			$encoded = preg_replace('/\s+/', '', (string) $encoded);

			// Checked before decoding, so a huge argument isn't decoded at all.
			if ((int) floor(strlen($encoded) * 3 / 4) > $max + 3) {
				throw new McpException(-32602, 'The file is larger than the ' . round($max / 1048576, 1) . ' MB upload limit.');
			}

			$bytes = base64_decode($encoded, true);

			if ($bytes === false) {
				throw new McpException(-32602, 'content_base64 is not valid base64.');
			}
			if ($filename === '') {
				throw new McpException(-32602, 'filename is required with content_base64 (e.g. "photo.jpg").');
			}
		} else {
			$url = $source === 'file'
				? (is_array($arguments['file']) ? (string) ($arguments['file']['download_url'] ?? '') : '')
				: (string) $arguments['url'];

			if ($url === '') {
				throw new McpException(-32602, 'file has no download_url (ChatGPT sent only a file id). Ask the user to '
					. 'attach the file again, or call create_media_upload_link and give them the link.');
			}

			if ($filename === '' && $source === 'file') {
				$filename = trim((string) ($arguments['file']['file_name'] ?? ''));
			}

			try {
				$fetched = SafeFetch::get($url, $max);
			} catch (\RuntimeException $e) {
				throw new McpException(-32602, 'Could not download the file: ' . $e->getMessage());
			}

			$bytes = $fetched['body'];

			if ($filename === '') {
				$filename = $fetched['filename'] !== '' ? $fetched['filename'] : self::nameFromUrl($fetched['url'], $fetched['content_type']);
			}
		}

		// No extension, but we know the type (a ChatGPT attachment's name can be bare).
		if (!str_contains(basename($filename), '.')) {
			$filename .= self::extensionFor($bytes);
		}

		$overwrite = (bool) ($arguments['overwrite'] ?? false);
		$stored    = MediaUploadHelper::store($actor, (string) ($arguments['folder'] ?? 'images'), $filename, $bytes, $overwrite);

		ActivityLog::info('mcp', 'media.upload', $actor->username . ' uploaded ' . $stored['path'] . ' (' . $stored['size'] . ' bytes, from ' . $source . ').', [
			'user_id' => (int) $actor->id,
			'subject' => $stored['path'],
		]);

		return ToolResult::json(['ok' => true, 'source' => $source] + $stored);
	}

	private static function nameFromUrl(string $url, string $contentType): string
	{
		$name = basename((string) parse_url($url, PHP_URL_PATH));

		if ($name !== '' && str_contains($name, '.')) {
			return rawurldecode($name);
		}

		return 'upload-' . gmdate('Ymd-His') . match ($contentType) {
			'image/jpeg'      => '.jpg',
			'image/png'       => '.png',
			'image/webp'      => '.webp',
			'image/gif'       => '.gif',
			'application/pdf' => '.pdf',
			default           => '',
		};
	}

	/** An extension from the content, for a name that has none. */
	private static function extensionFor(string $bytes): string
	{
		return match (true) {
			str_starts_with($bytes, "\xFF\xD8\xFF")        => '.jpg',
			str_starts_with($bytes, "\x89PNG")             => '.png',
			str_starts_with($bytes, 'GIF8')                => '.gif',
			str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => '.webp',
			str_starts_with($bytes, '%PDF-')               => '.pdf',
			default                                        => '',
		};
	}
}
