<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\WebServices\Csmcpforj\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Router\Route;

/**
 * Registers the Streamable-HTTP MCP route at /api/index.php/v1/mcp.
 * Without this plugin enabled the route 404s — Joomla's API router only
 * publishes routes that are registered via onBeforeApiRoute.
 */
final class Csmcpforj extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
	}

	public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
	{
		$router   = $event->getRouter();
		$defaults = ['component' => 'com_csmcpforj'];

		$router->addRoutes([
			// MCP protocol surface — JSON-RPC 2.0 over HTTP POST, token-gated.
			new Route(['POST'], 'v1/mcp', 'mcp.handle', [], $defaults),
			// Discovery / help response for browsers and curl-from-command-line
			// debugging. Public on purpose — returns no tool data, just describes
			// what the endpoint is and how MCP clients are expected to call it.
			// Without this, hitting the URL with GET returns Joomla's bare
			// "Resource not found" 404 which makes operators think the install
			// is broken.
			new Route(['GET'], 'v1/mcp', 'mcp.info', [], $defaults),
		]);
	}
}
