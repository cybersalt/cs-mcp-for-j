<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Catalog model.
 *
 * Responsible for fetching the remote catalog.json that lists every available
 * MCP add-on, caching it to the Joomla cache directory for cache_ttl_hours,
 * and exposing a force-refresh path for the manual refresh button.
 *
 * Cache layout (under JPATH_CACHE/com_csmcpforj/):
 *   catalog.json — raw JSON payload as fetched
 *   catalog.meta — JSON with fetched_at (unix ts) and source_url
 *
 * Falls back to packages/com_csmcpforj/admin/catalog.fallback.json (bundled
 * with the component) if the remote endpoint is unreachable AND no cache
 * exists. That bundled file ships from Phase 1c onward.
 */
final class CatalogModel extends BaseDatabaseModel
{
	private const CACHE_SUBDIR  = 'com_csmcpforj';
	private const CACHE_FILE    = 'catalog.json';
	private const META_FILE     = 'catalog.meta';
	private const FALLBACK_FILE = 'catalog.fallback.json';
	private const HTTP_TIMEOUT  = 8;

	/**
	 * Returns the resolved catalog payload as an associative array, with each
	 * addon enriched with its install state (installed / enabled / extension_id
	 * / installed_version) so the view can show toggle buttons.
	 *
	 * Shape:
	 *   ['addons' => [...], 'fetched_at' => int|null, 'source' => 'cache'|'remote'|'fallback'|'empty',
	 *    'source_url' => string, 'error' => string|null]
	 */
	public function getCatalog(bool $forceRefresh = false): array
	{
		$result = $this->getCatalogRaw($forceRefresh);
		$result['addons'] = $this->enrichWithInstallState($result['addons']);
		return $result;
	}

	/**
	 * Raw catalog fetch — no install-state lookup. Internal use only.
	 */
	private function getCatalogRaw(bool $forceRefresh = false): array
	{
		$params    = ComponentHelper::getParams('com_csmcpforj');
		$sourceUrl = $this->resolveCatalogEndpoint(
			(string) $params->get('catalog_url', 'https://www.cybersalt.com/index.php?option=com_csreleasemanager&task=api.catalog&format=json&catalog=cs-mcp-for-j')
		);
		$ttlHours  = max(1, min(168, (int) $params->get('cache_ttl_hours', 24)));

		$cachePath = $this->cacheDir();
		$cacheFile = $cachePath . '/' . self::CACHE_FILE;
		$metaFile  = $cachePath . '/' . self::META_FILE;

		if (!$forceRefresh && is_file($cacheFile) && is_file($metaFile)) {
			$meta = $this->readMeta($metaFile);
			$age  = time() - (int) ($meta['fetched_at'] ?? 0);
			if ($age >= 0 && $age < $ttlHours * 3600) {
				$decoded = $this->decodeCatalogFile($cacheFile);
				if ($decoded !== null) {
					return [
						'addons'                  => $decoded['addons'],
						'independence_notice_url' => $decoded['independence_notice_url'],
						'fetched_at'              => (int) ($meta['fetched_at'] ?? 0),
						'source'                  => 'cache',
						'source_url'              => (string) ($meta['source_url'] ?? $sourceUrl),
						'error'                   => null,
					];
				}
			}
		}

		[$body, $fetchError] = $this->fetchRemote($sourceUrl);

		if ($body !== null) {
			$this->writeCache($cachePath, $cacheFile, $metaFile, $body, $sourceUrl);
			$decoded = $this->decodeCatalogString($body);
			if ($decoded !== null) {
				return [
					'addons'                  => $decoded['addons'],
					'independence_notice_url' => $decoded['independence_notice_url'],
					'fetched_at'              => time(),
					'source'                  => 'remote',
					'source_url'              => $sourceUrl,
					'error'                   => null,
				];
			}
			$fetchError = 'Catalog response was not valid JSON.';
		}

		// Remote fetch failed — try stale cache before fallback.
		if (is_file($cacheFile)) {
			$decoded = $this->decodeCatalogFile($cacheFile);
			if ($decoded !== null) {
				$meta = is_file($metaFile) ? $this->readMeta($metaFile) : [];
				return [
					'addons'                  => $decoded['addons'],
					'independence_notice_url' => $decoded['independence_notice_url'],
					'fetched_at'              => (int) ($meta['fetched_at'] ?? 0),
					'source'                  => 'cache',
					'source_url'              => (string) ($meta['source_url'] ?? $sourceUrl),
					'error'                   => $fetchError,
				];
			}
		}

		// Last resort — bundled fallback shipped inside the component.
		$fallback = JPATH_ADMINISTRATOR . '/components/com_csmcpforj/' . self::FALLBACK_FILE;
		if (is_file($fallback)) {
			$decoded = $this->decodeCatalogFile($fallback);
			if ($decoded !== null) {
				return [
					'addons'                  => $decoded['addons'],
					'independence_notice_url' => $decoded['independence_notice_url'],
					'fetched_at'              => null,
					'source'                  => 'fallback',
					'source_url'              => $sourceUrl,
					'error'                   => $fetchError,
				];
			}
		}

		return [
			'addons'                  => [],
			'independence_notice_url' => '',
			'fetched_at'              => null,
			'source'                  => 'empty',
			'source_url'              => $sourceUrl,
			'error'                   => $fetchError,
		];
	}

	/**
	 * Walk each addon in the catalog and look up its install state in
	 * #__extensions, adding:
	 *
	 *   installed         (bool)   — row present in #__extensions
	 *   enabled           (bool)   — row present AND enabled=1
	 *   extension_id      (int)    — row id, or 0 if not installed
	 *   installed_version (string) — version from manifest_cache, or ''
	 *
	 * If an addon JSON entry has no addon_extension descriptor, the install
	 * state can't be detected and all four keys are still added with safe
	 * defaults so the template can branch on them without nullchecks.
	 *
	 * @param array<int, array<string,mixed>> $addons
	 * @return array<int, array<string,mixed>>
	 */
	private function enrichWithInstallState(array $addons): array
	{
		foreach ($addons as $i => $addon) {
			$descriptor = $addon['addon_extension'] ?? null;
			if (!is_array($descriptor) || empty($descriptor['type']) || empty($descriptor['element'])) {
				$addons[$i] += [
					'installed'         => false,
					'enabled'           => false,
					'extension_id'      => 0,
					'installed_version' => '',
				];
				continue;
			}

			$row = $this->lookupExtension(
				(string) $descriptor['type'],
				(string) ($descriptor['folder'] ?? ''),
				(string) $descriptor['element']
			);

			$addons[$i]['installed']         = $row !== null;
			$addons[$i]['enabled']           = $row !== null && (int) $row['enabled'] === 1;
			$addons[$i]['extension_id']      = $row !== null ? (int) $row['extension_id'] : 0;
			$addons[$i]['installed_version'] = $row !== null ? $this->extractVersion($row['manifest_cache'] ?? '') : '';
		}
		return $addons;
	}

	/**
	 * @return array{extension_id:int,enabled:int,manifest_cache:string}|null
	 */
	private function lookupExtension(string $type, string $folder, string $element): ?array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$q  = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'enabled', 'manifest_cache']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote($type))
			->where($db->quoteName('element') . ' = ' . $db->quote($element));

		if ($folder !== '') {
			$q->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
		}

		$row = $db->setQuery($q)->loadAssoc();
		return $row ?: null;
	}

	private function extractVersion(string $manifestCache): string
	{
		if ($manifestCache === '') {
			return '';
		}
		$decoded = json_decode($manifestCache, true);
		if (!is_array($decoded)) {
			return '';
		}
		return (string) ($decoded['version'] ?? '');
	}

	/**
	 * Resolve the configured catalog URL into the actual endpoint to fetch.
	 *
	 * Two shapes are supported so the same param works for both the new
	 * dynamic api.catalog endpoint and any operator who hand-points it at a
	 * static catalog.json on their own server:
	 *
	 *   - URL already has a query string OR ends in .json  → use as-is
	 *   - Anything else (bare base URL)                     → append /catalog.json
	 *
	 * The default ships pointing at the dynamic endpoint; the bare-base-URL
	 * branch exists for backward compatibility with older installs and for
	 * operators who prefer to self-host a static file.
	 */
	private function resolveCatalogEndpoint(string $configuredUrl): string
	{
		$url = trim($configuredUrl);
		if ($url === '') {
			return '';
		}
		if (str_contains($url, '?') || preg_match('~\\.json($|#|\\?)~i', $url) === 1) {
			return $url;
		}
		return rtrim($url, '/') . '/catalog.json';
	}

	private function cacheDir(): string
	{
		$base = Factory::getApplication()->get('cache_path', JPATH_CACHE);
		$dir  = rtrim((string) $base, '/\\') . '/' . self::CACHE_SUBDIR;
		// Native PHP mkdir — Joomla\CMS\Filesystem\Folder was deprecated in J4
		// and removed in J6. Suppress the warning so a race between two parallel
		// requests trying to create the same dir doesn't fatal-error one of them;
		// the is_dir check on the next call still confirms the dir exists.
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		return $dir;
	}

	/**
	 * @return array{0: string|null, 1: string|null} [body, error]
	 */
	private function fetchRemote(string $url): array
	{
		// Allow only https:// (and plain http:// for dev/local testing). Block
		// file://, gopher://, ftp://, etc. to keep an operator who can edit
		// the component config from turning the catalog refresh into an SSRF
		// or local-file-read primitive.
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['https', 'http'], true)) {
			return [null, 'Catalog URL must use http:// or https:// (got "' . $scheme . '://").'];
		}

		try {
			$http     = HttpFactory::getHttp();
			$response = $http->get($url, [], self::HTTP_TIMEOUT);
		} catch (\Throwable $e) {
			return [null, 'Network error: ' . $e->getMessage()];
		}

		if ((int) $response->code !== 200) {
			return [null, 'Catalog endpoint returned HTTP ' . (int) $response->code . '.'];
		}

		return [(string) $response->body, null];
	}

	private function writeCache(string $cacheDir, string $cacheFile, string $metaFile, string $body, string $sourceUrl): void
	{
		if (!is_dir($cacheDir)) {
			@mkdir($cacheDir, 0755, true);
		}
		@file_put_contents($cacheFile, $body);
		@file_put_contents($metaFile, json_encode([
			'fetched_at' => time(),
			'source_url' => $sourceUrl,
		], JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @return array{addons: array<int, array<string,mixed>>, independence_notice_url: string}|null
	 */
	private function decodeCatalogFile(string $file): ?array
	{
		$raw = @file_get_contents($file);
		if ($raw === false) {
			return null;
		}
		return $this->decodeCatalogString($raw);
	}

	/**
	 * Accepts either a top-level array of addon objects (legacy schema_version 1)
	 * or an object with an "addons" key (schema_version 2+). Returns the addon
	 * list plus any catalog-level metadata fields we care about (currently just
	 * `independence_notice_url`).
	 *
	 * @return array{addons: array<int, array<string,mixed>>, independence_notice_url: string}|null
	 */
	private function decodeCatalogString(string $raw): ?array
	{
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return null;
		}
		if (isset($data['addons']) && is_array($data['addons'])) {
			return [
				'addons'                  => array_values($data['addons']),
				'independence_notice_url' => (string) ($data['independence_notice_url'] ?? ''),
			];
		}
		if (array_is_list($data)) {
			// Legacy bare-array shape — no catalog-level metadata available.
			return [
				'addons'                  => $data,
				'independence_notice_url' => '',
			];
		}
		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function readMeta(string $metaFile): array
	{
		$raw = @file_get_contents($metaFile);
		if ($raw === false) {
			return [];
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}
}
