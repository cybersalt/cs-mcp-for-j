<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Setupguide;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;

/**
 * Setup Guide view — vendor-neutral setup + troubleshooting reference for
 * every conformant MCP client (Claude, Cursor, ChatGPT, and — as coverage
 * grows — Cline, Continue, Copilot, Gemini CLI, Windsurf, etc.).
 *
 * Design intent (2026-08-13, v2.4.1 MVP scope):
 *  - ONE view sibling to Dashboard + Browse Add-Ons, so it's discoverable via
 *    the standard component submenu.
 *  - Universal section (what is MCP, connection recipe, token generation,
 *    security model) rendered once, not per client.
 *  - Per-client tabs — Claude / Cursor / ChatGPT for the MVP release. Adding
 *    more clients later is a language-string + template addition, no PHP
 *    changes required.
 *  - Token localStorage backed by the SAME key the Dashboard uses so a token
 *    typed on either view propagates to the other. Never sent back to the
 *    server — pure browser-side substitution into copy-button payloads.
 *  - Troubleshooting + FAQ as native <details> collapsibles (matches the
 *    admin card pattern used in the Catalog view; no Bootstrap.collapse JS
 *    dependency).
 */
final class HtmlView extends BaseHtmlView
{
	public string $endpointUrl = '';
	public string $siteUrl     = '';
	public string $host        = '';

	/**
	 * Deep-link to the Dashboard view — the Easy Setup section links here
	 * prominently because the Dashboard hosts the copy-paste prompt that
	 * IS the recommended path for non-technical users. Feedback 2026-08-13
	 * from Tim: the guide was leading with jargon-heavy advanced setup
	 * instead of pointing to the easy path first.
	 */
	public string $dashboardUrl = '';

	/**
	 * Deep-link to the currently-logged-in admin's own profile edit page.
	 * The Joomla API Token tab lives on that page. Without the id query
	 * param, Joomla's task=user.edit falls through to the user list, which
	 * is not what we want.
	 */
	public string $tokenProfileUrl = '';

	public function display($tpl = null): void
	{
		$this->siteUrl         = rtrim(Uri::root(), '/');
		$this->endpointUrl     = $this->siteUrl . '/api/index.php/v1/mcp';
		$this->host            = parse_url($this->endpointUrl, PHP_URL_HOST) ?: 'site';
		$this->dashboardUrl    = Route::_('index.php?option=com_csmcpforj&view=dashboard');
		$this->tokenProfileUrl = $this->buildTokenProfileUrl();

		// Bootstrap tab JS isn't auto-loaded in Joomla admin — without this,
		// clicking a client tab (Claude / Cursor / ChatGPT) just changes the
		// URL hash and the panel never activates. Same opt-in pattern the
		// Dashboard view uses for its Setup Methods tabs.
		Factory::getApplication()->getDocument()->getWebAssetManager()->useScript('bootstrap.tab');

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ_SETUPGUIDE_TITLE'), 'cog');

		$toolbar = Toolbar::getInstance('toolbar');
		$toolbar->linkButton('dashboard', 'COM_CSMCPFORJ_TOOLBAR_DASHBOARD')
			->url(Route::_('index.php?option=com_csmcpforj&view=dashboard'))
			->icon('icon-dashboard');
		$toolbar->linkButton('catalog', 'COM_CSMCPFORJ_TOOLBAR_BROWSE_ADDONS')
			->url(Route::_('index.php?option=com_csmcpforj&view=catalog'))
			->icon('icon-cube');

		if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			ToolbarHelper::preferences('com_csmcpforj');
		}

		parent::display($tpl);
	}

	/**
	 * Deep-link to the currently-logged-in admin's profile edit page — the
	 * "Generate a token" instructions link to it so a first-time user isn't
	 * left hunting for Users → their-user → Joomla API Token tab.
	 */
	private function buildTokenProfileUrl(): string
	{
		$user = Factory::getApplication()->getIdentity();
		if (!$user || !$user->id) {
			return Route::_('index.php?option=com_users&view=users');
		}
		return Route::_('index.php?option=com_users&task=user.edit&id=' . (int) $user->id);
	}
}
