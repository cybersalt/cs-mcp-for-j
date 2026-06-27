<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

/**
 * Shared path-safety surface for the template-file tools.
 *
 * Template files can contain executable PHP — write access to one is
 * effectively RCE if the API token leaks or a tool argument is malformed.
 * The validation here is the single source of truth that every read/write
 * tool routes through. Each layer is intentionally strict-by-default; if
 * any one fails the tool aborts before touching disk.
 *
 *   1. Client must be 0 (site) or 1 (administrator).
 *   2. Template name must match the safe element pattern Joomla itself
 *      uses for template short names — lowercase alphanumeric + underscore
 *      + hyphen + optional dot suffix (e.g. cassiopeia, atum, my-template,
 *      cassiopeia.dark). No path characters, no spaces, no shell metas.
 *   3. Relative path must use forward slashes, must not contain `..`,
 *      must not begin with `/`. Empty path = root listing only (acceptable
 *      for list, rejected for read/write).
 *   4. Final resolved path (via realpath) must start with the canonical
 *      jail root. Catches symlink escapes that path-string checks miss.
 *   5. For write_template_file, the file extension must match the
 *      allowlist (CSS/JS/SCSS/JSON/images). PHP and other executables
 *      are denied in v1 — extending the allowlist is a deliberate
 *      future-version decision behind its own permission flag.
 */
trait TemplateFilePathTrait
{
	/** @var array<int, string> File extensions accepted by write_template_file in v1. */
	private const WRITE_ALLOWED_EXTENSIONS = [
		// text assets (the common case)
		'css', 'js', 'mjs', 'scss', 'sass', 'less',
		'json', 'map', 'svg', 'txt', 'md',
		// raster images (writable so an AI can replace e.g. a logo)
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
		// font assets
		'woff', 'woff2', 'ttf', 'otf', 'eot',
	];

	/**
	 * Resolve {client, template, path} to a canonical absolute path inside
	 * the template's media root. Throws \InvalidArgumentException on any
	 * validation failure. Returns the resolved path; caller is responsible
	 * for the actual filesystem operation.
	 *
	 * @param  int    $clientId    0 for site, 1 for administrator
	 * @param  string $template    Template short element name (e.g. 'cassiopeia')
	 * @param  string $relPath     Path within the template root, forward-slash separated
	 * @param  bool   $mustExist   If true, the resolved path must be an existing file
	 * @param  bool   $allowMissingForWrite If true, an absent target file is OK (write_*)
	 * @return string Canonical absolute path inside the jail
	 */
	protected function resolveTemplatePath(
		int $clientId,
		string $template,
		string $relPath,
		bool $mustExist,
		bool $allowMissingForWrite = false
	): string {
		if ($clientId !== 0 && $clientId !== 1) {
			throw new \InvalidArgumentException('client_id must be 0 (site) or 1 (administrator).');
		}

		$template = trim($template);
		if ($template === '' || preg_match('/^[a-z0-9_][a-z0-9._-]*$/', $template) !== 1) {
			throw new \InvalidArgumentException(
				'template must be a valid template element name (lowercase alphanumeric, '
				. 'underscore, hyphen, dot; no path separators).'
			);
		}

		$jailRoot = $this->templateJailRoot($clientId, $template);
		if (!is_dir($jailRoot)) {
			throw new \InvalidArgumentException(
				'Template not installed at this path: ' . $jailRoot
				. ' (the template exists in #__template_styles but has no media/templates/ folder).'
			);
		}

		$canonicalJail = realpath($jailRoot);
		if ($canonicalJail === false) {
			throw new \InvalidArgumentException('Template root could not be canonicalised: ' . $jailRoot);
		}

		$relPath = ltrim(str_replace('\\', '/', $relPath), '/');
		if ($relPath !== '' && (str_contains($relPath, '..') || str_contains($relPath, "\0"))) {
			throw new \InvalidArgumentException('path must not contain ".." or null bytes.');
		}

		$targetAbsolute = $relPath === ''
			? $canonicalJail
			: $canonicalJail . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

		// realpath() returns false for paths that don't exist. For writes we
		// allow the target file to be absent (it'll be created), but the
		// PARENT directory must exist and must canonicalise inside the jail.
		$resolved = realpath($targetAbsolute);
		if ($resolved === false) {
			if (!$allowMissingForWrite) {
				throw new \InvalidArgumentException('path does not exist: ' . $relPath);
			}
			// Validate the parent directory's realpath instead.
			$parent = dirname($targetAbsolute);
			$resolvedParent = realpath($parent);
			if ($resolvedParent === false) {
				throw new \InvalidArgumentException(
					'parent directory for new file does not exist: ' . dirname($relPath)
				);
			}
			if (!$this->isInsideJail($resolvedParent, $canonicalJail)) {
				throw new \InvalidArgumentException('path escapes the template jail.');
			}
			// Recompose target from the canonicalised parent + the original basename.
			return $resolvedParent . DIRECTORY_SEPARATOR . basename($targetAbsolute);
		}

		if (!$this->isInsideJail($resolved, $canonicalJail)) {
			throw new \InvalidArgumentException(
				'path escapes the template jail (symlink or similar). resolved='
				. $resolved . ' jail=' . $canonicalJail
			);
		}

		if ($mustExist && !is_file($resolved)) {
			throw new \InvalidArgumentException('path is not a file: ' . $relPath);
		}

		return $resolved;
	}

	/**
	 * Refuse writes whose file extension isn't on the v1 allowlist. PHP and
	 * other executable types are intentionally absent — adding them needs a
	 * separate explicit permission flag (deferred to a later release).
	 */
	protected function assertWritableExtension(string $absolutePath): void
	{
		$ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
		if ($ext === '') {
			throw new \InvalidArgumentException(
				'path has no file extension; only allowlisted extensions can be written. '
				. 'Allowed: ' . implode(', ', self::WRITE_ALLOWED_EXTENSIONS)
			);
		}
		if (!in_array($ext, self::WRITE_ALLOWED_EXTENSIONS, true)) {
			throw new \InvalidArgumentException(
				'extension .' . $ext . ' is not on the writable allowlist. '
				. 'Allowed: ' . implode(', ', self::WRITE_ALLOWED_EXTENSIONS) . '. '
				. 'Editing .php files is intentionally not supported in this version — '
				. 'use the Joomla admin Template Files editor for PHP overrides.'
			);
		}
	}

	private function templateJailRoot(int $clientId, string $template): string
	{
		$client = $clientId === 1 ? 'administrator' : 'site';
		return JPATH_ROOT . '/media/templates/' . $client . '/' . $template;
	}

	private function isInsideJail(string $resolved, string $canonicalJail): bool
	{
		// Normalize both to canonical form + ensure jail ends with separator
		// so a sibling directory like /var/www/cassiopeia-evil/ can't pass
		// the str_starts_with check against /var/www/cassiopeia/.
		$jailWithSep = rtrim($canonicalJail, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $resolved === $canonicalJail || str_starts_with($resolved, $jailWithSep);
	}

	/**
	 * Pull client_id from arguments accepting 0/1 or "site"/"administrator".
	 * Defaults to 0 (site) since that's the 95% case.
	 */
	protected function parseClientId(array $arguments): int
	{
		$raw = $arguments['client'] ?? $arguments['client_id'] ?? 'site';
		if (is_int($raw)) {
			return $raw === 1 ? 1 : 0;
		}
		$lower = strtolower(trim((string) $raw));
		if ($lower === '1' || $lower === 'administrator' || $lower === 'admin') {
			return 1;
		}
		return 0;
	}
}
