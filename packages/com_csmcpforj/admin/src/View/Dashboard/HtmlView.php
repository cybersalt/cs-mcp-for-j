<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

final class HtmlView extends BaseHtmlView
{
	public string $endpointUrl = '';

	/**
	 * Tools grouped by domain.
	 *
	 * @var array<string, array<int, array{name:string, description:string}>>
	 */
	public array $toolsByDomain = [];

	public int $toolCount = 0;

	public function display($tpl = null): void
	{
		$this->endpointUrl = rtrim(Uri::root(), '/') . '/api/index.php/v1/mcp';

		$registry   = new ToolRegistry();
		$dispatcher = Factory::getApplication()->getDispatcher();
		$dispatcher->dispatch(RegisterToolsEvent::EVENT_NAME, new RegisterToolsEvent($registry));

		$this->toolsByDomain = $this->groupTools($registry);
		$this->toolCount     = count($registry->all());

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ'), 'cog');

		parent::display($tpl);
	}

	/**
	 * Try to get the BUILTIN_TOOLS map from the system plugin so tools can be
	 * shown grouped by domain. Falls back to a single "Other" group if the
	 * system plugin isn't loaded for some reason.
	 */
	private function groupTools(ToolRegistry $registry): array
	{
		$pluginClass = '\\Cybersalt\\Plugin\\System\\Csmcpforj\\Extension\\Csmcpforj';
		$nameToDomain = [];

		if (class_exists($pluginClass) && method_exists($pluginClass, 'getBuiltinTools')) {
			foreach ($pluginClass::getBuiltinTools() as $domain => $toolClasses) {
				foreach ($toolClasses as $toolClass) {
					if (class_exists($toolClass)) {
						$instance = new $toolClass($this->getDb());
						$nameToDomain[$instance->getName()] = $domain;
					}
				}
			}
		}

		$grouped = [];
		foreach ($registry->all() as $tool) {
			$domain = $nameToDomain[$tool->getName()] ?? 'Other';
			$grouped[$domain][] = [
				'name'        => $tool->getName(),
				'description' => $tool->getDescription(),
				'permission'  => $tool->getRequiredPermission(),
			];
		}

		ksort($grouped);
		return $grouped;
	}

	private function getDb(): \Joomla\Database\DatabaseInterface
	{
		return Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
	}
}
