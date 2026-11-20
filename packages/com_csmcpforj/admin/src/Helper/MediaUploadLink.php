<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * The upload link page (2.14.0): how a file attached in claude.ai, Claude
 * Desktop or Claude mobile reaches the site.
 *
 * Those apps don't hand an attached file to an MCP server (only ChatGPT has a
 * file parameter, see UploadMediaTool), so create_media_upload_link gives the
 * AI app a link to pass on; the person opens it, picks the photos or PDFs, and
 * they go through exactly the same checks and processing as upload_media, as
 * that person.
 *
 * The link is the credential, so:
 *   - it is signed (HMAC-SHA256 with a key derived from the site secret) and
 *     carries the user, the folder and an expiry (Options → Media uploads,
 *     default 30 minutes); nothing is stored, nothing to clean up;
 *   - the key also covers the user's password hash, so changing the password
 *     kills every link that user made;
 *   - on every use the user must still exist, not be blocked or due a password
 *     reset, and still hold MCP for J's Write and Media's Create permissions;
 *   - uploads land only in the folder the link names (subfolders the person
 *     can't choose), at most 20 files a time, every upload logged;
 *   - the page can't be framed, sends no referrer and is never cached.
 * Anyone who gets the link before it expires can upload as that person into
 * that folder, the same as anyone holding their API token could; the expiry
 * keeps that window short.
 */
final class MediaUploadLink
{
	public const QUERY_KEY = 'csmcpforj_upload';

	private const MAX_FILES = 20;

	/** @return array{url: string, expires: string, minutes: int} */
	public static function create(User $user, string $folderDisplay): array
	{
		$minutes = max(5, min(1440, (int) MediaUploadHelper::params()->get('media_upload_link_minutes', 30)));
		$expires = time() + $minutes * 60;
		$payload = self::b64(json_encode([
			'u' => (int) $user->id,
			'f' => $folderDisplay,
			'e' => $expires,
			'n' => bin2hex(random_bytes(6)),
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$token = $payload . '.' . self::b64(hash_hmac('sha256', $payload, self::key($user), true));

		return [
			'url'     => MediaUploadHelper::siteRoot() . '/index.php?' . self::QUERY_KEY . '=' . $token,
			'expires' => gmdate('Y-m-d H:i:s', $expires),
			'minutes' => $minutes,
		];
	}

	/** Answer the page (GET) or the upload (POST), then end the request. */
	public static function handle(CMSApplication $app): void
	{
		$token = (string) ($_GET[self::QUERY_KEY] ?? '');

		try {
			[$user, $folder, $expires] = self::verify($token);
		} catch (\RuntimeException $e) {
			ActivityLog::warning('mcp', 'media.upload_link', 'Upload link refused: ' . $e->getMessage());
			self::page(self::t('COM_CSMCPFORJ_UPLOAD_LINK_INVALID_TITLE'), '<p class="lead">' . self::e($e->getMessage()) . '</p>'
				. '<p class="muted">' . self::e(self::t('COM_CSMCPFORJ_UPLOAD_LINK_ASK_AGAIN')) . '</p>', 403);
			$app->close();

			return;
		}

		// Joomla's upload checks and the content events see this user, as in the Media Manager.
		$app->loadIdentity($user);

		$results = [];

		if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
			$results = self::receive($user, $folder);
		}

		self::render($token, $folder, $expires, $results);
		$app->close();
	}

	/**
	 * @return array{0: User, 1: string, 2: int}
	 *
	 * @throws \RuntimeException with a message for the person
	 */
	private static function verify(string $token): array
	{
		$parts = explode('.', $token);

		if (count($parts) !== 2 || strlen($token) > 2000) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BROKEN'));
		}

		$data = json_decode((string) self::unb64($parts[0]), true);

		if (!is_array($data) || empty($data['u']) || !isset($data['f'], $data['e'])) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BROKEN'));
		}

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $data['u']);

		if (empty($user->id)) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BROKEN'));
		}

		$expected = self::b64(hash_hmac('sha256', $parts[0], self::key($user), true));

		if (!hash_equals($expected, $parts[1])) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BROKEN'));
		}

		if ((int) $data['e'] < time()) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_EXPIRED'));
		}

		if ($user->block || (int) $user->requireReset === 1) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_ACCOUNT'));
		}

		try {
			PermissionHelper::requireWrite($user);
			MediaUploadHelper::requireCreate($user);
		} catch (McpException $e) {
			throw new \RuntimeException(self::t('COM_CSMCPFORJ_UPLOAD_LINK_PERMISSION'));
		}

		return [$user, (string) $data['f'], (int) $data['e']];
	}

	/** @return array<int, array{name: string, ok: bool, text: string}> */
	private static function receive(User $user, string $folder): array
	{
		$files = $_FILES['files'] ?? null;

		if (!is_array($files) || !is_array($files['name'] ?? null)) {
			$max = ini_get('post_max_size');

			return [['name' => '', 'ok' => false, 'text' => self::t('COM_CSMCPFORJ_UPLOAD_LINK_NOTHING', (string) $max)]];
		}

		$out   = [];
		$count = min(count($files['name']), self::MAX_FILES);

		for ($i = 0; $i < $count; $i++) {
			$name = (string) $files['name'][$i];

			if ($name === '') {
				continue;
			}

			if ((int) $files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $files['tmp_name'][$i])) {
				$out[] = ['name' => $name, 'ok' => false, 'text' => in_array((int) $files['error'][$i], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
					? self::t('COM_CSMCPFORJ_UPLOAD_LINK_TOO_LARGE_SERVER', (string) ini_get('upload_max_filesize'))
					: self::t('COM_CSMCPFORJ_UPLOAD_LINK_FAILED')];
				continue;
			}

			try {
				$bytes  = (string) file_get_contents((string) $files['tmp_name'][$i]);
				$stored = MediaUploadHelper::store($user, $folder, $name, $bytes, false);

				ActivityLog::info('mcp', 'media.upload_link', $user->username . ' uploaded ' . $stored['path'] . ' through an upload link (' . $stored['size'] . ' bytes).', [
					'user_id' => (int) $user->id,
					'subject' => $stored['path'],
				]);

				$notes = $stored['processing']['notes'] ?? [];
				$out[] = ['name' => $name, 'ok' => true, 'text' => $stored['path'] . ($notes !== [] ? ' — ' . implode(' ', $notes) : '')];
			} catch (\Throwable $e) {
				$out[] = ['name' => $name, 'ok' => false, 'text' => $e instanceof McpException ? $e->getMessage() : self::t('COM_CSMCPFORJ_UPLOAD_LINK_FAILED')];

				if (!$e instanceof McpException) {
					ActivityLog::exception('mcp', 'media.upload_link', $e, ['user_id' => (int) $user->id]);
				}
			}
		}

		if (count($files['name']) > self::MAX_FILES) {
			$out[] = ['name' => '', 'ok' => false, 'text' => self::t('COM_CSMCPFORJ_UPLOAD_LINK_MAX_FILES', (string) self::MAX_FILES)];
		}

		return $out;
	}

	private static function render(string $token, string $folder, int $expires, array $results): void
	{
		$action  = MediaUploadHelper::siteRoot() . '/index.php?' . self::QUERY_KEY . '=' . rawurlencode($token);
		$minutes = max(0, (int) ceil(($expires - time()) / 60));
		$body    = '';

		if ($results !== []) {
			$body .= '<ul class="results">';
			foreach ($results as $r) {
				$body .= '<li class="' . ($r['ok'] ? 'ok' : 'bad') . '">' . ($r['ok'] ? '&#10003; ' : '&#10007; ')
					. ($r['name'] !== '' ? '<strong>' . self::e($r['name']) . '</strong>: ' : '') . self::e($r['text']) . '</li>';
			}
			$body .= '</ul><p class="lead">' . self::e(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BACK_TO_CHAT')) . '</p><hr>';
		}

		$max   = round(MediaUploadHelper::maxBytes() / 1048576, 1);
		$body .= '<p class="lead">' . self::t('COM_CSMCPFORJ_UPLOAD_LINK_INTRO', '<strong>' . self::e($folder) . '</strong>') . '</p>'
			. '<form method="post" enctype="multipart/form-data" action="' . self::e($action) . '">'
			. '<p><input type="file" name="files[]" multiple required accept="image/*,application/pdf,.pdf,.doc,.docx,.odt,.xls,.xlsx,.ppt,.pptx,.txt,.csv"></p>'
			. '<p><button type="submit" class="allow">' . self::e(self::t('COM_CSMCPFORJ_UPLOAD_LINK_BUTTON')) . '</button></p>'
			. '</form>'
			. '<p class="muted">' . self::e(self::t('COM_CSMCPFORJ_UPLOAD_LINK_LIMITS', (string) $max, (string) self::MAX_FILES, (string) $minutes)) . '</p>';

		self::page(self::t('COM_CSMCPFORJ_UPLOAD_LINK_TITLE'), $body);
	}

	private static function page(string $title, string $bodyHtml, int $status = 200): void
	{
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Frame-Options: DENY');
		header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
		header('Referrer-Policy: no-referrer');
		header('X-Robots-Tag: noindex, nofollow');

		$app  = Factory::getApplication();
		$site = self::e((string) $app->get('sitename', 'Joomla'));

		echo '<!doctype html><html lang="' . self::e($app->getLanguage()->getTag()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow"><title>' . self::e($title) . ' - ' . $site . '</title><style>'
			. ':root{color-scheme:light dark}body{font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f4f5f7;color:#1f2328}'
			. 'main{max-width:560px;margin:48px auto;padding:28px 32px;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
			. 'h1{font-size:1.35rem;margin:0 0 4px}.site{color:#57606a;font-size:.9rem;margin:0 0 20px}.lead{font-size:1.05rem}.muted{color:#57606a;font-size:.92rem}'
			. 'button{font:inherit;padding:10px 22px;border-radius:6px;border:1px solid transparent;cursor:pointer}.allow{background:#1f7a3a;color:#fff}'
			. 'input[type=file]{font:inherit;max-width:100%}.results{padding-left:0;list-style:none}.results li{margin:6px 0;word-break:break-word}.ok{color:#1f7a3a}.bad{color:#b42318}'
			. 'hr{border:0;border-top:1px solid #d0d7de;margin:20px 0}'
			. '@media (prefers-color-scheme:dark){body{background:#0d1117;color:#e6edf3}main{background:#161b22;box-shadow:none}.site,.muted{color:#9da7b3}.ok{color:#3fb950}.bad{color:#f85149}}'
			. '@media (max-width:600px){main{margin:16px;padding:20px 16px}}'
			. '</style></head><body><main><h1>' . self::e($title) . '</h1><p class="site">' . $site . ' &middot; MCP for J</p>'
			. $bodyHtml . '</main></body></html>';
	}

	/** Per user: the site secret plus the user's password hash, so a password change ends their links. */
	private static function key(User $user): string
	{
		$secret = (string) Factory::getApplication()->get('secret', '');

		return hash('sha256', 'csmcpforj-upload-link|' . $secret . '|' . (int) $user->id . '|' . (string) $user->password, true);
	}

	private static function b64(string $raw): string
	{
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private static function unb64(string $text): string|false
	{
		return base64_decode(strtr($text, '-_', '+/'), true);
	}

	private static function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private static bool $languageLoaded = false;

	/** English first, the site language over it, as on the OAuth pages. */
	private static function t(string $key, string ...$args): string
	{
		if (!self::$languageLoaded) {
			self::$languageLoaded = true;
			$lang = Factory::getApplication()->getLanguage();

			foreach (['en-GB', $lang->getTag()] as $tag) {
				$lang->load('com_csmcpforj', JPATH_SITE . '/components/com_csmcpforj', $tag, true, false);
			}
		}

		return $args === [] ? Text::_($key) : Text::sprintf($key, ...$args);
	}
}
