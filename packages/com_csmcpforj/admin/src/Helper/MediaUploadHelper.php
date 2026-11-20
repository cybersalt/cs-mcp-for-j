<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;

/**
 * Uploads into the Media Manager's folders (images/, files/...), shared by
 * the Media tools and the upload link page (2.14.0, gap 2 of the
 * "three API gaps"; asked for by Michel Campaner, 2026-11-20).
 *
 * Everything goes through Joomla's own Media API (com_media ApiModel →
 * the filesystem adapter), so the site's Media settings apply exactly as in
 * the Media Manager: LocalAdapter::checkContent() runs MediaHelper::canUpload()
 * (allowed extensions, executables buried in the name, MIME check, maximum
 * size, the SVG sanitiser) and Path::check() keeps the file inside the folder.
 * The content-plugin and media-action events fire too, so an image-resize
 * media-action plugin works as it would in the admin.
 *
 * Checked here as well, before Joomla sees the file, so the answer can say
 * exactly why:
 *   - Joomla's Create permission in Media (core.create on com_media), as the
 *     Media Manager's upload button needs. Delete needs core.delete.
 *   - MCP for J's own size cap (Options → Media uploads), never above
 *     Media's own upload_maxsize.
 *   - the folder: one of the Media Manager's folders, no "..", no backslash,
 *     no control characters.
 *   - SVG refused unless switched on in Options (an SVG can carry script).
 *   - the content must match the extension for images (a PHP file renamed
 *     .jpg is not an image and is refused).
 *
 * ⚠️ ApiModel::isMediaFile() reads the REQUEST's `mediatypes` input and
 * defaults to images only, so without setting it every PDF is refused as
 * "not a media file" from an MCP request. allMediaTypes() sets it.
 */
final class MediaUploadHelper
{
	public static function params(): Registry
	{
		return ComponentHelper::getParams('com_csmcpforj');
	}

	/** The largest file accepted, in bytes: our cap, never above Media's own. */
	public static function maxBytes(): int
	{
		$ours  = max(1, min(200, (int) self::params()->get('media_max_upload_mb', 20))) * 1048576;
		$media = (int) round((float) ComponentHelper::getParams('com_media')->get('upload_maxsize', 0) * 1048576);

		return $media > 0 ? min($ours, $media) : $ours;
	}

	public static function apiModel(): object
	{
		self::allMediaTypes();

		return Factory::getApplication()->bootComponent('com_media')->getMVCFactory()
			->createModel('Api', 'Administrator', ['ignore_request' => true]);
	}

	/** See the class docblock: without this, ApiModel treats only images as media files. */
	public static function allMediaTypes(): void
	{
		Factory::getApplication()->getInput()->set('mediatypes', '0,1,2,3');
	}

	/**
	 * The Media Manager's folders on this site.
	 *
	 * @return array<int, array{adapter: string, root: string, name: string}>
	 */
	public static function adapters(object $model): array
	{
		$out = [];

		foreach ($model->getProviderManager()->getProviders() as $provider) {
			foreach ($provider->getAdapters() as $adapter) {
				$out[] = [
					'adapter' => $provider->getID() . '-' . $adapter->getAdapterName(),
					'root'    => trim(str_replace('\\', '/', $adapter->getAdapterName()), '/'),
					'name'    => (string) $provider->getDisplayName(),
				];
			}
		}

		return $out;
	}

	/**
	 * A folder as the AI app names it ("images/news/2026", "files", or Joomla's
	 * own "local-images:/news") → the adapter and the path inside it.
	 *
	 * @return array{adapter: string, root: string, path: string, display: string}
	 */
	public static function resolveFolder(object $model, string $folder): array
	{
		$folder = trim($folder);

		if ($folder === '') {
			$folder = 'images';
		}

		if (preg_match('/[\x00-\x1f\x7f\\\\]/', $folder)) {
			throw new McpException(-32602, 'The folder name contains a backslash or a control character.');
		}

		$adapters = self::adapters($model);

		// Joomla's own form: "local-images:/news/2026".
		if (preg_match('/^([a-z0-9_]+-[^:\/]+):(.*)$/i', $folder, $m)) {
			foreach ($adapters as $a) {
				if (strcasecmp($a['adapter'], $m[1]) === 0) {
					$path = self::cleanPath($m[2]);

					return ['adapter' => $a['adapter'], 'root' => $a['root'], 'path' => $path, 'display' => $a['root'] . rtrim($path, '/')];
				}
			}
		}

		$clean = trim(str_replace('//', '/', $folder), '/');

		// Longest root first, so "images/products" wins over "images".
		usort($adapters, static fn (array $a, array $b): int => strlen($b['root']) <=> strlen($a['root']));

		foreach ($adapters as $a) {
			if ($clean === $a['root'] || str_starts_with($clean, $a['root'] . '/')) {
				$path = self::cleanPath(substr($clean, strlen($a['root'])));

				return ['adapter' => $a['adapter'], 'root' => $a['root'], 'path' => $path, 'display' => $a['root'] . rtrim($path, '/')];
			}
		}

		throw new McpException(-32602, 'The folder "' . $folder . '" is not in one of this site\'s Media folders ('
			. implode(', ', array_map(static fn (array $a): string => $a['root'] . '/', $adapters)) . '). '
			. 'A Super User can add another folder in System → Plugins → FileSystem - Local.');
	}

	/** "/news/2026" style, no "." or ".." segments. */
	private static function cleanPath(string $path): string
	{
		$segments = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $s): bool => $s !== ''));

		foreach ($segments as $segment) {
			if ($segment === '.' || $segment === '..') {
				throw new McpException(-32602, 'The folder may not contain "." or ".." segments.');
			}
		}

		return '/' . implode('/', $segments);
	}

	public static function requireCreate(User $user): void
	{
		if (!$user->authorise('core.create', 'com_media')) {
			throw new McpException(-32002, 'Permission denied: uploading needs Joomla\'s Create permission in Media '
				. '(System → Global Configuration → Permissions, or Media → Options → Permissions), the same as the '
				. 'Media Manager\'s Upload button.');
		}
	}

	public static function requireDelete(User $user): void
	{
		if (!$user->authorise('core.delete', 'com_media')) {
			throw new McpException(-32002, 'Permission denied: deleting media needs Joomla\'s Delete permission in Media.');
		}
	}

	public static function requireBrowse(User $user): void
	{
		if (!$user->authorise('core.manage', 'com_media') && !$user->authorise('core.create', 'com_media')) {
			throw new McpException(-32002, 'Permission denied: browsing media needs Joomla\'s Access Administration '
				. 'Interface or Create permission in Media.');
		}
	}

	/**
	 * Store one file through Joomla's Media API, after our checks and the image
	 * processing from Options.
	 *
	 * @return array<string, mixed> what was stored
	 */
	public static function store(User $actor, string $folder, string $filename, string $bytes, bool $overwrite): array
	{
		self::requireCreate($actor);

		$model    = self::apiModel();
		$target   = self::resolveFolder($model, $folder);
		$max      = self::maxBytes();
		$filename = self::safeName($filename);

		if ($bytes === '') {
			throw new McpException(-32602, 'The file is empty.');
		}
		if (strlen($bytes) > $max) {
			throw new McpException(-32602, 'The file is ' . round(strlen($bytes) / 1048576, 1) . ' MB; the limit on this site is '
				. round($max / 1048576, 1) . ' MB (MCP for J Options → Media uploads, and Media → Options → Maximum Size).');
		}

		$ext = strtolower(File::getExt($filename));

		if ($ext === 'svg' && !self::params()->get('media_allow_svg', 0)) {
			throw new McpException(-32602, 'SVG uploads are switched off in MCP for J (an SVG file can carry script). '
				. 'A Super User can allow them in Components → MCP for Joomla → Options → Media uploads.');
		}

		$imageTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
		$processing = ['processed' => false, 'notes' => []];

		if (isset($imageTypes[$ext])) {
			$info = @getimagesizefromstring($bytes);

			if ($info === false || ($info['mime'] ?? '') !== $imageTypes[$ext]) {
				throw new McpException(-32602, 'The file is named .' . $ext . ' but its content is '
					. ($info === false ? 'not an image' : 'a ' . $info['mime'] . ' image') . '. Nothing was uploaded.');
			}

			try {
				[$bytes, $filename, $processing] = ImageProcessor::process($bytes, $filename, $info, self::params());
			} catch (\DomainException $e) {
				throw new McpException(-32602, $e->getMessage());
			}
		}

		$app = Factory::getApplication();

		try {
			$storedName = $model->createFile($target['adapter'], $filename, $target['path'], $bytes, $overwrite);
		} catch (\Joomla\Component\Media\Administrator\Exception\FileExistsException $e) {
			throw new McpException(-32602, 'A file named ' . $filename . ' already exists in ' . $target['display']
				. '. Pass overwrite: true to replace it, or choose another name.');
		} catch (\Throwable $e) {
			throw new McpException(-32602, 'Joomla\'s Media Manager refused the file: ' . self::mediaReason($app, $e));
		}

		$relative = $target['display'] . '/' . $storedName;
		$size     = strlen($bytes);
		$result   = [
			'path'       => $relative,
			'url'        => self::siteRoot() . '/' . implode('/', array_map('rawurlencode', explode('/', $relative))),
			'media_path' => $target['adapter'] . ':' . rtrim($target['path'], '/') . '/' . $storedName,
			'name'       => $storedName,
			'size'       => $size,
			'processing' => $processing,
		];

		if (isset($processing['width'])) {
			$result['width']  = $processing['width'];
			$result['height'] = $processing['height'];
		}

		return $result;
	}

	/**
	 * A file name Joomla will accept as is. LocalAdapter makes names safe
	 * itself, but silently: "Town Hall Photo.JPG" would land as
	 * "Town-Hall-Photo.jpg" while the app is told the first. Doing it here
	 * means the answer names the real file.
	 */
	public static function safeName(string $filename): string
	{
		$filename = basename(str_replace('\\', '/', trim($filename)));
		$ext      = strtolower(File::getExt($filename));
		$base     = $ext !== '' ? substr($filename, 0, -(strlen($ext) + 1)) : $filename;

		// Spaces to hyphens, then Joomla's own rule.
		$base = preg_replace('/\s+/u', '-', $base);
		$safe = File::makeSafe($base . ($ext !== '' ? '.' . $ext : ''));

		if ($safe === '' || $safe === '.' . $ext || $ext === '') {
			throw new McpException(-32602, 'The file name "' . $filename . '" is not usable: it needs a name and an extension (photo.jpg).');
		}

		return $safe;
	}

	/** Joomla's own reason: canUpload() puts it in the message queue, the exception only says "upload failed". */
	private static function mediaReason(object $app, \Throwable $e): string
	{
		$reasons = [];

		try {
			foreach ((array) $app->getMessageQueue(true) as $message) {
				if (($message['type'] ?? '') === 'error' || ($message['type'] ?? '') === 'danger') {
					$reasons[] = strip_tags((string) $message['message']);
				}
			}
		} catch (\Throwable $ignored) {
		}

		$text = $reasons !== [] ? implode(' ', array_unique($reasons)) : $e->getMessage();

		return ($text !== '' ? $text : 'no reason given') . ' (Joomla checks the extension and MIME type against Media → Options.)';
	}

	/** The site's address, not the API application's. */
	public static function siteRoot(): string
	{
		$root = rtrim(Uri::root(), '/');

		return preg_replace('#/api$#', '', $root);
	}
}
