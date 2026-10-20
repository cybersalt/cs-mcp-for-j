<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;

/**
 * What create_module and update_module share. Field names come from Joomla
 * 6.2.0's administrator/components/com_modules/forms/module.xml (Module tab,
 * Menu Assignment tab) and advanced.xml (Advanced tab, stored in params).
 */
trait ModuleFieldsTrait
{
	/** Shared schema text for the params object. */
	protected static function moduleParamsHelp(): string
	{
		return 'Module options stored in params. The Advanced tab keys every module has: layout '
			. '(e.g. "_:default"), moduleclass_sfx, module_tag (div, section, aside, nav, header, footer...), '
			. 'bootstrap_size (0-12), header_tag (h1-h6, p, div), header_class, style (module chrome, '
			. 'e.g. "Cassiopeia-card", "System-none"), cache, cache_time, cachemode. Everything else '
			. 'is the module type\'s own options.';
	}

	/**
	 * Validate and normalise a publish_up / publish_down argument.
	 *
	 * null = not supplied; '' = clear (Table\Module::check() turns it into
	 * NULL); otherwise a UTC 'Y-m-d H:i:s'. Joomla's form converts from the
	 * editor's timezone (filter="user_utc"); there is no form here, so the
	 * value is taken as UTC, the way the column is stored.
	 */
	protected function normaliseModuleDate(array $arguments, string $key): ?string
	{
		if (!array_key_exists($key, $arguments)) {
			return null;
		}

		$raw = $arguments[$key];

		if ($raw === null || trim((string) $raw) === '') {
			return '';
		}

		try {
			return (new Date((string) $raw, 'UTC'))->toSql();
		} catch (\Throwable $e) {
			throw new \InvalidArgumentException($key . ' must be a date/time such as "2026-11-01 09:00:00" (UTC), or an empty string to clear it.');
		}
	}

	/**
	 * Translate the tool's assignment vocabulary into ModuleModel::save()'s.
	 *
	 * Issue #17: ModuleModel::save() reads $data['assignment'] as the MODE
	 * (0 = all, 1 = only selected, -1 = all except, non-numeric = none) and
	 * $data['assigned'] as the ARRAY of menu item ids. The tool schema uses
	 * the opposite names (assigned = mode: -1 all, 0 none, 1 only, 2 except;
	 * assignment = ids), and passing them through unchanged made the model
	 * delete the old #__modules_menu rows and insert nothing.
	 *
	 * @return array{assignment:int|string, assigned:int[]}
	 */
	protected function moduleAssignmentPayload(int $mode, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map(static fn ($v) => abs((int) $v), $ids))));

		if (in_array($mode, [1, 2], true) && $ids === []) {
			throw new \InvalidArgumentException('assignment[] (menu item ids) is required when assigned is 1 or 2.');
		}

		return match ($mode) {
			0       => ['assignment' => '-', 'assigned' => []],   // no pages
			1       => ['assignment' => 1,   'assigned' => $ids], // only selected
			2       => ['assignment' => -1,  'assigned' => $ids], // all except selected
			default => ['assignment' => 0,   'assigned' => []],   // -1: all pages
		};
	}

	/**
	 * Language tag => module id, as AdminModel::save() expects $data['associations'].
	 *
	 * @return array<string,int>
	 */
	protected function normaliseModuleAssociations(mixed $raw): array
	{
		if (!is_array($raw)) {
			throw new \InvalidArgumentException('associations must be an object of language tag => module id, e.g. {"fr-FR": 123}.');
		}

		$out = [];

		foreach ($raw as $tag => $id) {
			$tag = trim((string) $tag);

			if ($tag === '' || $tag === '*' || (int) $id <= 0) {
				throw new \InvalidArgumentException('associations must map language tags such as "fr-FR" to module ids.');
			}

			$out[$tag] = (int) $id;
		}

		return $out;
	}
}
