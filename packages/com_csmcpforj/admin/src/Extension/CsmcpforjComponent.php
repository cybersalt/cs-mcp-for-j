<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\MVCComponent;

/**
 * Component shell. The MCP tool registry is built per-request inside
 * McpController and HtmlView (Dashboard) — there is no long-lived registry
 * stored on the component instance.
 */
final class CsmcpforjComponent extends MVCComponent
{
}
