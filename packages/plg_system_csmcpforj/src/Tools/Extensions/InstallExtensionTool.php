<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\User\User;

/**
 * Install a Joomla extension from a URL or a local zip file path.
 *
 * Equivalent to Joomla's Install From URL / Install From Folder admin GUI.
 * Used for one-shot installs and as the engine behind the v2.0 catalog's
 * "Install add-on" buttons. Joomla itself ships no Web Services endpoint
 * for this, so without this MCP tool an agent has to hand the zip URL to
 * the operator and ask them to click through the admin GUI.
 *
 * Equivalence to Joomla admin GUI:
 *   - Install From URL:    pass `url`
 *   - Install From Folder: pass `path` (must resolve inside tmp_path)
 *
 * Triggers a real Installer run, so all postflight scripts execute, all
 * #__extensions rows are written, and all assets get registered exactly
 * as if the operator clicked through the GUI.
 *
 * Security:
 *   - Hard-gated to core.admin on com_installer (Super Users always pass).
 *     A regular csmcpforj.write grant is NOT sufficient: installing an
 *     extension = arbitrary PHP execution on the server.
 *   - URL scheme allowlist: http:// and https:// only. No file:// /
 *     gopher:// / etc.
 *   - When a local `path` is given it MUST resolve inside the configured
 *     Joomla tmp_path — no traversal to /etc or anywhere else on the box.
 *   - Every call is logged via the application's message queue plus the
 *     standard Joomla install logger.
 */
final class InstallExtensionTool extends AbstractTool
{
	public function getName(): string { return 'install_extension'; }

	public function getDescription(): string
	{
		return 'Install (or upgrade) a Joomla extension from a URL or local zip. '
			. 'REQUIRED ARG: url (http/https URL of the package zip) OR path (path to a '
			. 'zip already on the server, inside the configured tmp_path). Triggers a real '
			. 'Joomla Installer run, so all postflight scripts execute and #__extensions '
			. 'rows are written exactly as if the operator clicked Install From URL in the '
			. 'GUI. Same code path handles both first-time install and upgrade. '
			. 'SECURITY: hard-gated to core.admin on com_installer (Super Users always '
			. 'pass) regardless of csmcpforj.write grants — installing an extension is '
			. 'equivalent to arbitrary PHP execution on the server. Only install from '
			. 'sources you trust.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'properties' => [
				'url'  => ['type' => 'string', 'description' => 'http:// or https:// URL of the package zip. Pass this OR path.'],
				'path' => ['type' => 'string', 'description' => 'Server-relative path to a zip already on disk, inside the configured Joomla tmp_path.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		if (!$actor->authorise('core.admin', 'com_installer')) {
			return ToolResult::error(
				'install_extension requires core.admin on com_installer '
				. '(typically Super User). The csmcpforj.write grant alone is not sufficient '
				. 'because installing an extension = running arbitrary PHP on the server.'
			);
		}

		$url  = trim((string) ($arguments['url'] ?? ''));
		$path = trim((string) ($arguments['path'] ?? ''));

		if ($url === '' && $path === '') {
			return ToolResult::error('Provide either url (http(s) URL) or path (local zip).');
		}
		if ($url !== '' && $path !== '') {
			return ToolResult::error('Provide url OR path, not both.');
		}

		$app     = Factory::getApplication();
		$tmpPath = rtrim((string) $app->get('tmp_path'), '/\\');

		// 1. Resolve to a local zip the Installer can unpack.
		[$localZip, $downloaded, $error] = $this->resolveLocalZip($url, $path, $tmpPath);
		if ($error !== null) {
			return ToolResult::error($error);
		}

		// 2. Unpack the zip into a temp directory.
		$package = InstallerHelper::unpack($localZip, true);
		if (!$package || empty($package['extractdir']) || empty($package['dir'])) {
			$this->cleanup($localZip, null, $downloaded);
			return ToolResult::error('Failed to unpack the package zip (invalid archive or no write permission to tmp_path).');
		}

		// 3. Install through Joomla's standard Installer. This runs the
		//    extension's own install script (preflight/install/postflight),
		//    populates #__extensions, registers assets, etc.
		$installer = new Installer();
		$installer->setDatabase($this->db);

		$ok = false;
		try {
			$ok = (bool) $installer->install($package['extractdir']);
		} catch (\Throwable $e) {
			$this->cleanup($localZip, $package, $downloaded);
			return ToolResult::error('Installer threw: ' . $e->getMessage());
		}

		// 4. Pull what we can about the result before cleanup.
		$manifest = $installer->manifest ?? null;
		$type     = (string) ($package['type'] ?? '');
		$element  = $this->extractElement($manifest, $type);
		$version  = $manifest && isset($manifest->version) ? (string) $manifest->version : '';
		$name     = $manifest && isset($manifest->name) ? (string) $manifest->name : '';

		// 5. Cleanup tmp files.
		$this->cleanup($localZip, $package, $downloaded);

		if (!$ok) {
			$queueErrors = $this->drainErrorMessages($app);
			return ToolResult::error(
				'Joomla installer reported failure'
				. ($queueErrors !== '' ? '. Installer messages: ' . $queueErrors : '.')
			);
		}

		return ToolResult::json([
			'ok'      => true,
			'name'    => $name,
			'element' => $element,
			'type'    => $type,
			'version' => $version,
			'source'  => $url !== '' ? 'url' : 'path',
		]);
	}

	/**
	 * Either downloads the URL into tmp_path, or sanity-checks that the given
	 * local path lives inside tmp_path. Returns a 3-tuple [localZip, downloaded, error].
	 *
	 * @return array{0: string|null, 1: bool, 2: string|null}
	 */
	private function resolveLocalZip(string $url, string $path, string $tmpPath): array
	{
		if ($url !== '') {
			$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
			if (!in_array($scheme, ['http', 'https'], true)) {
				return [null, false, 'url must use http:// or https:// (got "' . $scheme . '://").'];
			}

			$downloadedName = InstallerHelper::downloadPackage($url);
			if (!$downloadedName) {
				return [null, false, 'Failed to download package from ' . $url . '. The URL may be unreachable or returning a non-zip body.'];
			}
			return [$tmpPath . DIRECTORY_SEPARATOR . $downloadedName, true, null];
		}

		// Local path: resolve and make sure it sits inside the configured tmp_path.
		$realTmp = realpath($tmpPath);
		$realZip = realpath($path);
		if ($realTmp === false) {
			return [null, false, 'Could not resolve tmp_path (' . $tmpPath . ').'];
		}
		if ($realZip === false) {
			// Allow relative paths inside tmp_path: prepend tmp and re-resolve.
			$realZip = realpath($tmpPath . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
		}
		if ($realZip === false || !is_file($realZip)) {
			return [null, false, 'path does not point to an existing file: ' . $path];
		}
		if (!str_starts_with($realZip, $realTmp . DIRECTORY_SEPARATOR) && $realZip !== $realTmp) {
			return [null, false, 'path must resolve inside the configured Joomla tmp_path (got: ' . $realZip . ', expected prefix: ' . $realTmp . ').'];
		}
		return [$realZip, false, null];
	}

	/**
	 * Best-effort extraction of the installed element name from the manifest.
	 * Joomla's Installer doesn't expose a single getElement() method across
	 * adapter types in a stable way, so we read it off the parsed manifest:
	 *   - component: <element>com_xxx</element>
	 *   - plugin:    <element>xxx</element> (folder + element together)
	 *   - module:    <element>mod_xxx</element>
	 *   - package:   <packagename>xxx</packagename> → "pkg_xxx"
	 */
	private function extractElement(?\SimpleXMLElement $manifest, string $type): string
	{
		if ($manifest === null) {
			return '';
		}
		if ($type === 'package') {
			$name = (string) ($manifest->packagename ?? '');
			return $name !== '' ? 'pkg_' . $name : '';
		}
		return (string) ($manifest->element ?? '');
	}

	private function cleanup(?string $localZip, ?array $package, bool $downloaded): void
	{
		if ($localZip && $downloaded && is_file($localZip)) {
			@unlink($localZip);
		}
		if ($package && !empty($package['extractdir'])) {
			InstallerHelper::cleanupInstall($localZip ?: '', $package['extractdir']);
		}
	}

	/**
	 * Drains application message queue and returns concatenated error-type
	 * messages, so a failed install reports the actual Joomla error rather
	 * than just "Installer reported failure".
	 */
	private function drainErrorMessages(object $app): string
	{
		if (!method_exists($app, 'getMessageQueue')) {
			return '';
		}
		$messages = $app->getMessageQueue(true) ?? [];
		$errors   = [];
		foreach ($messages as $m) {
			$type = (string) ($m['type'] ?? '');
			if (in_array($type, ['error', 'warning'], true)) {
				$errors[] = (string) ($m['message'] ?? '');
			}
		}
		return implode(' | ', array_filter($errors));
	}
}
