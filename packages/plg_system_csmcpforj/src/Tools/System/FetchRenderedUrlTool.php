<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Fetch a rendered page from the same Joomla site so an agent can verify its
 * writes worked — e.g. confirm a Schema.org JSON-LD block actually appears
 * in <head>, or that a meta description landed on the article.
 *
 * Restricted to the SAME ORIGIN as the Joomla site (no SSRF to internal
 * services, no external proxy). Optionally extracts JSON-LD blocks since
 * that's the dominant verification use case.
 */
final class FetchRenderedUrlTool extends AbstractTool
{
	private const MAX_BYTES = 1_500_000; // ~1.5 MB cap so agents don't pull a full image gallery

	public function getName(): string { return 'fetch_rendered_url'; }

	public function getDescription(): string
	{
		return 'Fetch a rendered page from this same Joomla site (same-origin only — no SSRF). '
			. 'Required: path (relative path or full URL on this site). Optional: extract_jsonld=true '
			. 'returns parsed JSON-LD blocks from <script type="application/ld+json"> tags so the agent '
			. 'can verify Schema.org writes landed without re-grepping the HTML. Response cap ~1.5 MB.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['path'],
			'properties' => [
				'path'           => ['type' => 'string', 'description' => 'Relative path (e.g. "/about-us") or full URL on this site.'],
				'extract_jsonld' => ['type' => 'boolean', 'description' => 'If true, also return parsed JSON-LD blocks. Default false.'],
				'include_html'   => ['type' => 'boolean', 'description' => 'If false, omit the raw HTML body from the response (handy when only extract_jsonld is needed). Default true.'],
				'timeout'        => ['type' => 'integer', 'description' => 'HTTP request timeout in seconds. Default 15, max 60.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$path        = $this->requireString($arguments, 'path');
		$extractJ    = (bool) ($arguments['extract_jsonld'] ?? false);
		$includeHtml = !isset($arguments['include_html']) || (bool) $arguments['include_html'];
		$timeout     = max(1, min(60, (int) ($arguments['timeout'] ?? 15)));

		// Normalise to absolute URL on this site, then sanity-check the host.
		$siteRoot = rtrim(Uri::root(), '/');
		$siteHost = strtolower((string) parse_url($siteRoot, PHP_URL_HOST));

		$absoluteUrl = $path;
		if (!preg_match('#^https?://#i', $path)) {
			if (str_starts_with($path, '/')) {
				$absoluteUrl = $siteRoot . $path;
			} else {
				$absoluteUrl = $siteRoot . '/' . $path;
			}
		}

		$urlHost = strtolower((string) parse_url($absoluteUrl, PHP_URL_HOST));
		if ($urlHost !== $siteHost) {
			return ToolResult::error('Refusing to fetch off-site URL. Got host=' . $urlHost . ', expected ' . $siteHost);
		}

		try {
			$http     = HttpFactory::getHttp();
			$response = $http->get($absoluteUrl, [], $timeout);
		} catch (\Throwable $e) {
			return ToolResult::error('HTTP fetch failed: ' . $e->getMessage());
		}

		$body   = (string) $response->body;
		$bytes  = strlen($body);
		$status = (int) $response->code;

		if ($bytes > self::MAX_BYTES) {
			$body = substr($body, 0, self::MAX_BYTES);
		}

		// Joomla's HTTP response stores headers as either string or array
		// (multi-value headers come back as arrays). Casting an array to
		// string emits a PHP warning, which corrupted the JSON-RPC envelope
		// in v1.5.0 — see the ob_start guard in McpController for the
		// structural fix; this is the local fix.
		$ctRaw = $response->headers['Content-Type']
			?? $response->headers['content-type']
			?? '';
		$contentType = is_array($ctRaw) ? implode(', ', $ctRaw) : (string) $ctRaw;

		$result = [
			'url'          => $absoluteUrl,
			'status'       => $status,
			'bytes'        => $bytes,
			'truncated'    => $bytes > self::MAX_BYTES,
			'content_type' => $contentType,
		];

		if ($extractJ) {
			$jsonld = [];
			if (preg_match_all(
				'#<script[^>]*type=(["\'])application/ld\+json\1[^>]*>(.*?)</script>#is',
				$body,
				$matches,
				PREG_SET_ORDER
			)) {
				foreach ($matches as $m) {
					$raw     = trim($m[2]);
					$decoded = json_decode($raw, true);
					$jsonld[] = [
						'raw'     => $raw,
						'parsed'  => $decoded,
						'parse_ok' => json_last_error() === JSON_ERROR_NONE,
					];
				}
			}
			$result['jsonld_blocks'] = $jsonld;
			$result['jsonld_count']  = count($jsonld);

			// Flat dedup'd list of every @type seen across all blocks
			// (recursing into @graph entries). The common SEO question is
			// "did my X type land?" — without this the agent has to walk
			// every block to answer.
			$result['jsonld_types'] = $this->collectJsonldTypes($jsonld);
		}

		if ($includeHtml) {
			$result['body'] = $body;
		}

		return ToolResult::json($result);
	}

	/**
	 * Walks every parsed JSON-LD block (recursing into @graph entries) and
	 * returns a flat, sorted, dedup'd list of @type values.
	 *
	 * @param array<int, array{parsed?: mixed}> $blocks
	 * @return array<int, string>
	 */
	private function collectJsonldTypes(array $blocks): array
	{
		$types = [];
		foreach ($blocks as $block) {
			$this->extractTypes($block['parsed'] ?? null, $types);
		}
		$types = array_values(array_unique($types));
		sort($types);
		return $types;
	}

	private function extractTypes(mixed $node, array &$types): void
	{
		if (!is_array($node)) {
			return;
		}
		if (isset($node['@type'])) {
			$t = $node['@type'];
			if (is_array($t)) {
				foreach ($t as $one) {
					if (is_string($one) && $one !== '') {
						$types[] = $one;
					}
				}
			} elseif (is_string($t) && $t !== '') {
				$types[] = $t;
			}
		}
		// Recurse into @graph and any nested arrays/objects.
		foreach ($node as $key => $value) {
			if (is_array($value)) {
				if ($key === '@graph' || array_is_list($value)) {
					foreach ($value as $child) {
						$this->extractTypes($child, $types);
					}
				} else {
					$this->extractTypes($value, $types);
				}
			}
		}
	}
}
