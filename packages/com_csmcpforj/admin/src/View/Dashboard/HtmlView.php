<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * Dashboard view. Reads tool metadata STATICALLY from the bundled plugin
 * classes — does NOT dispatch RegisterToolsEvent. Dispatching the event
 * here once caused a 512MB OOM (see RegisterToolsEvent for the postmortem),
 * and dispatch isn't necessary anyway: the dashboard only needs to show
 * what the bundled plugins ship, not what every event subscriber on the
 * site might want to register.
 */
final class HtmlView extends BaseHtmlView
{
	public string $endpointUrl = '';

	/** @var array<string, array<int, array{name:string, description:string, permission:string}>> */
	public array $toolsByDomain = [];

	public int $toolCount = 0;

	private const PLUGIN_TOOL_MAPS = [
		// Each entry: domain label => [pluginClass, methodReturningToolClasses]
		// The core system plugin returns a {domain => [classes]} map already.
		'__core' => [
			'\\Cybersalt\\Plugin\\System\\Csmcpforj\\Extension\\Csmcpforj',
			'getBuiltinTools',
		],
		// Add-ons each return a flat list of tool classes under one domain.
		'4SEO' => [
			'\\Cybersalt\\Plugin\\System\\Csmcpforj4seo\\Extension\\Csmcpforj4seo',
			'getToolClasses',
		],
	];

	public function display($tpl = null): void
	{
		$this->endpointUrl = rtrim(Uri::root(), '/') . '/api/index.php/v1/mcp';

		$grouped = [];

		foreach (self::PLUGIN_TOOL_MAPS as $domainHint => [$pluginClass, $method]) {
			if (!class_exists($pluginClass) || !method_exists($pluginClass, $method)) {
				continue;
			}

			$result = $pluginClass::$method();

			// Core plugin: returns ['Domain' => [class, ...], ...].
			// Add-ons: return [class, ...] keyed numerically.
			$isMap = is_array($result) && !empty($result) && !array_is_list($result);

			if ($isMap) {
				foreach ($result as $domain => $toolClasses) {
					$grouped[$domain] = array_merge($grouped[$domain] ?? [], $this->extractToolMeta($toolClasses));
				}
			} else {
				$domain = $domainHint;
				$grouped[$domain] = array_merge($grouped[$domain] ?? [], $this->extractToolMeta($result));
			}
		}

		ksort($grouped);
		$this->toolsByDomain = $grouped;
		$this->toolCount     = array_sum(array_map('count', $grouped));

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ'), 'cog');

		parent::display($tpl);
	}

	/**
	 * Instantiate each tool class once to read its name/description/permission.
	 * Tools are constructed with the database service. Lightweight — no event
	 * dispatch, no registry, just N lazy reads.
	 *
	 * @param array<int, class-string> $toolClasses
	 */
	private function extractToolMeta(array $toolClasses): array
	{
		$db   = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$out  = [];
		foreach ($toolClasses as $toolClass) {
			if (!class_exists($toolClass)) {
				continue;
			}
			try {
				$instance = new $toolClass($db);
				$out[] = [
					'name'        => $instance->getName(),
					'description' => $instance->getDescription(),
					'permission'  => $instance->getRequiredPermission(),
				];
			} catch (\Throwable $e) {
				// Skip tools that fail to instantiate — don't blow up the dashboard.
			}
		}
		return $out;
	}
}
