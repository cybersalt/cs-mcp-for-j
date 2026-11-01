<?php

/**
 * @package     Cybersalt.MCPforJ
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;

/**
 * Link to MCP for J's own Options screen (GitHub #51).
 *
 * The Dashboard and Setup Guide used to build this with Route::_()'s default
 * xhtml=true (which already turns & into &amp;) and then escape it again in the
 * template. The browser got "&amp;view=component", so Joomla saw only
 * option=com_config and opened Global Configuration, on whichever tab was used
 * last. Here the URL is built unencoded; templates escape it exactly once.
 *
 * $tab opens one tab of the Options form: Joomla's tab element activates the tab
 * whose id matches the URL hash, and the ids are the config.xml fieldset names
 * ("oauth", "server", "permissions", ...). $returnTo is where Save & Close and
 * Close go back to.
 */
final class OptionsLink
{
	public static function url(string $tab = '', string $returnTo = 'index.php?option=com_csmcpforj&view=dashboard'): string
	{
		$url = 'index.php?option=com_config&view=component&component=com_csmcpforj';

		if ($returnTo !== '') {
			$url .= '&return=' . urlencode(base64_encode($returnTo));
		}

		return Route::_($url, false) . ($tab !== '' ? '#' . $tab : '');
	}
}
