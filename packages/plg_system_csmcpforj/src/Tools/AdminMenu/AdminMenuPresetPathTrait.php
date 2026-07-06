<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu;

\defined('_JEXEC') or die;

/**
 * Shared path-safety surface for the admin-menu-preset tools.
 *
 * The Joomla admin sidebar is NOT driven from #__menu in Joomla 4+ — it's
 * rendered at request time from preset XMLs on disk, under
 * `administrator/components/<component>/presets/<name>.xml`. Diagnosing a
 * missing sidebar item (attacker plant? sloppy template dev? third-party
 * postflight?) means reading those XMLs and diffing them against stock.
 *
 * A general read_file tool would solve this but the blast radius is enormous
 * (configuration.php exfiltration, silent recon over the whole webroot). So
 * this trait implements a *narrowly scoped* allowlist that admits ONLY
 * `administrator/components/<component>/presets/<name>.xml`, validated by
 * component + name regex, path-constructed server-side (no raw path input),
 * and confirmed by realpath() to stay inside the allowlist base — catching
 * symlink escape attempts that string-prefix checks would miss.
 *
 * See ISSUE-6-get_admin_menu_preset-scoped-file-read.md for the full threat
 * model and design rationale.
 */
trait AdminMenuPresetPathTrait
{
	/** @var int Response-size hard cap (bytes). Presets are typically 5–15 KB; anything much bigger is suspicious. */
	private const MAX_PRESET_BYTES = 262144;

	/**
	 * Validate {component, name} and return the canonical absolute path to the
	 * preset XML. Throws \InvalidArgumentException on any validation failure.
	 * Caller is responsible for opening the file after this returns.
	 *
	 * @param string $component Joomla component element (e.g. "com_menus")
	 * @param string $name      Preset short name (no extension, no path chars)
	 * @return string           Canonical absolute path inside the allowlist jail
	 */
	protected function resolvePresetPath(string $component, string $name): string
	{
		$component = trim($component);
		$name      = trim($name);

		if (preg_match('/^com_[a-z0-9_]+$/', $component) !== 1) {
			throw new \InvalidArgumentException(
				'component must match ^com_[a-z0-9_]+$ (e.g. "com_menus", "com_content").'
			);
		}
		if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
			throw new \InvalidArgumentException(
				'name must match ^[a-z0-9_-]+$ (lowercase alphanumeric, underscore, hyphen). '
				. 'Any path separator, "..", leading dot, or extension in the name is refused.'
			);
		}

		$jailBase = JPATH_ADMINISTRATOR . '/components';
		$canonicalJail = realpath($jailBase);
		if ($canonicalJail === false) {
			throw new \InvalidArgumentException(
				'Allowlist base could not be canonicalised: ' . $jailBase
			);
		}

		$candidate = $jailBase . '/' . $component . '/presets/' . $name . '.xml';

		$resolved = realpath($candidate);
		if ($resolved === false) {
			// Distinguish "file doesn't exist" from "path escapes jail" so the
			// AI can surface a clean 404-shaped response rather than a security
			// warning. The parent-directory realpath still confirms the jail.
			$parent = dirname($candidate);
			$resolvedParent = realpath($parent);
			if ($resolvedParent === false || !$this->isInsideJail($resolvedParent, $canonicalJail)) {
				throw new \InvalidArgumentException(
					'preset path escapes the admin-components allowlist or its parent does not exist.'
				);
			}
			throw new PresetNotFoundException(
				'No preset at ' . $this->relativeToRoot($candidate) . '.'
			);
		}

		if (!$this->isInsideJail($resolved, $canonicalJail)) {
			throw new \InvalidArgumentException(
				'preset path escapes the admin-components allowlist (symlink or similar). '
				. 'resolved=' . $resolved . ' jail=' . $canonicalJail
			);
		}

		// is_file() (not is_link() alone) confirms we're reading a regular
		// file, not chasing a symlink to somewhere else. is_file() follows
		// symlinks by default, but the realpath jail check above already
		// enforced that the FINAL destination is inside the allowlist — so
		// this belt-and-braces check just catches the "resolved to a dir or
		// special file" edge case.
		if (!is_file($resolved)) {
			throw new PresetNotFoundException(
				'Path exists but is not a regular file: ' . $this->relativeToRoot($resolved)
			);
		}

		if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'xml') {
			throw new \InvalidArgumentException(
				'Only .xml preset files can be read; got extension .'
				. pathinfo($resolved, PATHINFO_EXTENSION)
			);
		}

		return $resolved;
	}

	/**
	 * Enumerate every preset XML under administrator/components/[com_*]/presets/.
	 * Returns per-preset descriptors that the tools then decorate with hashes.
	 *
	 * @return array<int, array{component: string, name: string, path: string, absolute: string}>
	 */
	protected function discoverAllPresets(): array
	{
		$out = [];
		$adminComponents = JPATH_ADMINISTRATOR . '/components';
		if (!is_dir($adminComponents)) {
			return $out;
		}

		$dh = @opendir($adminComponents);
		if ($dh === false) {
			return $out;
		}

		while (($entry = readdir($dh)) !== false) {
			if ($entry === '.' || $entry === '..' || strpos($entry, 'com_') !== 0) {
				continue;
			}
			$presetsDir = $adminComponents . '/' . $entry . '/presets';
			if (!is_dir($presetsDir)) {
				continue;
			}
			$xmls = glob($presetsDir . '/*.xml') ?: [];
			foreach ($xmls as $abs) {
				if (!is_file($abs)) {
					continue;
				}
				$name = pathinfo($abs, PATHINFO_FILENAME);
				$out[] = [
					'component' => $entry,
					'name'      => $name,
					'path'      => $this->relativeToRoot($abs),
					'absolute'  => $abs,
				];
			}
		}
		closedir($dh);

		usort($out, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
		return $out;
	}

	/**
	 * Read a preset file's contents, enforcing the size cap. Returns the raw
	 * bytes. Throws \RuntimeException on read failure OR if the file is
	 * larger than MAX_PRESET_BYTES.
	 */
	protected function readPresetBytes(string $absolute): string
	{
		$size = filesize($absolute);
		if ($size === false) {
			throw new \RuntimeException('Could not stat preset file: ' . $this->relativeToRoot($absolute));
		}
		if ($size > self::MAX_PRESET_BYTES) {
			throw new \RuntimeException(
				'Preset file is larger than the ' . self::MAX_PRESET_BYTES . '-byte cap ('
				. $size . ' bytes). Refusing to load — a preset XML that large is almost '
				. 'certainly wrong. Inspect it directly on the filesystem.'
			);
		}
		$bytes = @file_get_contents($absolute);
		if ($bytes === false) {
			throw new \RuntimeException('Could not read preset file: ' . $this->relativeToRoot($absolute));
		}
		return $bytes;
	}

	/**
	 * Returns null (matches_stock unknown) if there is no bundled hash for the
	 * current Joomla version + preset. The stock-hash table is opt-in and can
	 * be extended by editing this method; see the class docblock for how to
	 * generate hashes from a stock Joomla install.
	 *
	 * @return array{stock_sha256: string|null, matches_stock: bool|null, stock_version_tested: string|null}
	 */
	protected function stockHashLookup(string $component, string $name, string $observedSha): array
	{
		// Small hash table intentionally shipped EMPTY in v1. Populate keyed by
		// [joomla-short-version][component][name] => sha256 to enable
		// matches_stock detection. Generate on a stock J5.4.6 install with:
		//   find administrator/components/*/presets -name '*.xml' \
		//     | xargs -I{} sha256sum {}
		// then transcribe here.
		//
		// Format:
		//   $STOCK_HASHES = [
		//     '5.4.6' => [
		//       'com_menus'   => ['default' => 'abc123…', 'system' => 'def…'],
		//       'com_content' => ['content' => '…'],
		//     ],
		//   ];
		$STOCK_HASHES = [];

		$joomlaVersion = $this->currentJoomlaShortVersion();
		$table = $STOCK_HASHES[$joomlaVersion][$component][$name] ?? null;

		if ($table === null) {
			return [
				'stock_sha256'         => null,
				'matches_stock'        => null,
				'stock_version_tested' => null,
			];
		}

		return [
			'stock_sha256'         => $table,
			'matches_stock'        => hash_equals($table, $observedSha),
			'stock_version_tested' => $joomlaVersion,
		];
	}

	protected function currentJoomlaShortVersion(): string
	{
		if (class_exists(\Joomla\CMS\Version::class)) {
			try {
				return (new \Joomla\CMS\Version())->getShortVersion();
			} catch (\Throwable $e) {
				// fall through
			}
		}
		return '';
	}

	private function isInsideJail(string $resolved, string $canonicalJail): bool
	{
		$jailWithSep = rtrim($canonicalJail, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $resolved === $canonicalJail || str_starts_with($resolved, $jailWithSep);
	}

	private function relativeToRoot(string $absolute): string
	{
		$root = JPATH_ROOT;
		if (str_starts_with($absolute, $root)) {
			return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root))), '/');
		}
		return str_replace(DIRECTORY_SEPARATOR, '/', $absolute);
	}
}
