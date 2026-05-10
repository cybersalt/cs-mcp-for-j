<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP\Event;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\Event\Event;

/**
 * Dispatched once per MCP request, after authentication, to give plugins a
 * chance to register tools into the registry. Plugins should call
 * $event->getRegistry()->register($tool) for each tool they expose.
 *
 * Event name: onCsMcpRegisterTools
 *
 * IMPORTANT: this extends the plain Joomla\Event\Event, NOT
 * Joomla\CMS\Event\AbstractEvent. AbstractEvent's argument processor walks
 * argument values via reflection, which blew up to a 512MB OOM when the
 * "registry" argument carried 50+ Tool instances each holding a
 * DatabaseInterface reference. The plain base Event just stores the args
 * array and stays out of the way.
 */
final class RegisterToolsEvent extends Event
{
	public const EVENT_NAME = 'onCsMcpRegisterTools';

	public function __construct(ToolRegistry $registry)
	{
		parent::__construct(self::EVENT_NAME, ['registry' => $registry]);
	}

	public function getRegistry(): ToolRegistry
	{
		return $this->getArgument('registry');
	}
}
