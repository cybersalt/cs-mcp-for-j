<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Field;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

/**
 * The tool domains installed on this site, for Options → Tool access.
 *
 * Read from the same registration event the MCP endpoint uses, so add-on
 * domains (4SEO, AcyMailing...) appear as soon as the add-on is installed.
 * A domain saved in a rule whose add-on was later removed stays listed, so
 * saving the form again doesn't silently drop it.
 */
final class ToolDomainsField extends ListField
{
	protected $type = 'ToolDomains';

	protected function getOptions(): array
	{
		$counts  = [];
		$sources = [];

		try {
			$registry = new ToolRegistry();
			Factory::getApplication()->getDispatcher()->dispatch(RegisterToolsEvent::EVENT_NAME, new RegisterToolsEvent($registry));

			foreach ($registry->all() as $tool) {
				$domain          = strtolower($tool->getCategory());
				$counts[$domain] = ($counts[$domain] ?? 0) + 1;

				// Add-ons name their areas after their own screens ("Config",
				// "Status", "Versions"), so say which add-on each comes from. A
				// shared name covers every add-on listed, as ToolAccess matches
				// on the area name alone.
				if (preg_match('/\\\\Plugin\\\\System\\\\Csmcpforj(\w+)\\\\/', get_class($tool), $m)) {
					$sources[$domain][strtolower($m[1])] = true;
				}
			}
		} catch (\Throwable $e) {
			// A broken add-on must not break the Options screen; whatever
			// registered before it is still listed.
		}

		foreach ((array) $this->value as $saved) {
			$saved = strtolower(trim((string) $saved));
			if ($saved !== '' && !isset($counts[$saved])) {
				$counts[$saved] = 0;
			}
		}

		ksort($counts);

		$options = [];
		foreach ($counts as $domain => $count) {
			$label = ucwords(str_replace('_', ' ', $domain)) . ' (' . $count . ')';

			if (!empty($sources[$domain])) {
				$label .= ' · ' . implode(', ', array_keys($sources[$domain]));
			}
			$options[] = HTMLHelper::_('select.option', $domain, $label);
		}

		return array_merge(parent::getOptions(), $options);
	}
}
