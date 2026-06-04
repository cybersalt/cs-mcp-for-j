<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Folder;
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
	 * Returns the resolved catalog payload as an associative array.
	 *
	 * Shape:
	 *   ['addons' => [...], 'fetched_at' => int|null, 'source' => 'cache'|'remote'|'fallback'|'empty',
	 *    'source_url' => string, 'error' => string|null]
	 */
	public function getCatalog(bool $forceRefresh = false): array
	{
		$params    = ComponentHelper::getParams('com_csmcpforj');
		$sourceUrl = rtrim((string) $params->get('catalog_url', 'https://cybersalt.com/cs-mcp-for-j/'), '/') . '/catalog.json';
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
						'addons'     => $decoded,
						'fetched_at' => (int) ($meta['fetched_at'] ?? 0),
						'source'     => 'cache',
						'source_url' => (string) ($meta['source_url'] ?? $sourceUrl),
						'error'      => null,
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
					'addons'     => $decoded,
					'fetched_at' => time(),
					'source'     => 'remote',
					'source_url' => $sourceUrl,
					'error'      => null,
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
					'addons'     => $decoded,
					'fetched_at' => (int) ($meta['fetched_at'] ?? 0),
					'source'     => 'cache',
					'source_url' => (string) ($meta['source_url'] ?? $sourceUrl),
					'error'      => $fetchError,
				];
			}
		}

		// Last resort — bundled fallback shipped inside the component.
		$fallback = JPATH_ADMINISTRATOR . '/components/com_csmcpforj/' . self::FALLBACK_FILE;
		if (is_file($fallback)) {
			$decoded = $this->decodeCatalogFile($fallback);
			if ($decoded !== null) {
				return [
					'addons'     => $decoded,
					'fetched_at' => null,
					'source'     => 'fallback',
					'source_url' => $sourceUrl,
					'error'      => $fetchError,
				];
			}
		}

		return [
			'addons'     => [],
			'fetched_at' => null,
			'source'     => 'empty',
			'source_url' => $sourceUrl,
			'error'      => $fetchError,
		];
	}

	private function cacheDir(): string
	{
		$base = Factory::getApplication()->get('cache_path', JPATH_CACHE);
		$dir  = rtrim((string) $base, '/\\') . '/' . self::CACHE_SUBDIR;
		if (!is_dir($dir)) {
			Folder::create($dir);
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
			Folder::create($cacheDir);
		}
		@file_put_contents($cacheFile, $body);
		@file_put_contents($metaFile, json_encode([
			'fetched_at' => time(),
			'source_url' => $sourceUrl,
		], JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @return array<int, array<string,mixed>>|null
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
	 * Accepts either a top-level array of addon objects or an object with an
	 * "addons" key containing the array. Returns a normalized list or null on
	 * parse failure.
	 *
	 * @return array<int, array<string,mixed>>|null
	 */
	private function decodeCatalogString(string $raw): ?array
	{
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return null;
		}
		if (isset($data['addons']) && is_array($data['addons'])) {
			return array_values($data['addons']);
		}
		if (array_is_list($data)) {
			return $data;
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
