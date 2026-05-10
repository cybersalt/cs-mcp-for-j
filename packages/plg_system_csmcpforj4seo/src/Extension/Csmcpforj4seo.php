<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * 4SEO MCP add-on plugin. Registers a tool set that introspects and writes
 * #__forseo_* tables — 4SEO has no public Web Services API so this is the
 * pragmatic path until Weeblr ships one.
 *
 * Tools fall into three groups:
 *  - introspection (list/describe tables, count rows, dump component params)
 *  - safe generic CRUD restricted to forseo_* tables
 *  - component params merge (modify com_forseo settings via #__extensions)
 */
final class Csmcpforj4seo extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\List4seoTablesTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Describe4seoTableTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Count4seoRowsTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Get4seoComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Get4seoComponentParamsTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Set4seoComponentParamsTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Get4seoConfigTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Query4seoTableTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Insert4seoRowTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Update4seoRowTool::class,
		\Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Delete4seoRowTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	/**
	 * Tool list for the dashboard's domain map. Used by the component dashboard
	 * to render this add-on's tools under their own group heading.
	 */
	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
