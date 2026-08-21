<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Support;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Support view — the fourth admin submenu item alongside Dashboard,
 * Browse MCP Add-Ons, and Setup Guide.
 *
 * Two-tier UX by Pro membership status (Tim 2026-08-19):
 *   Pro members  → priority-support framing, "we get back to you fast"
 *   Free members → friendly "we love ideas / priority comes with Pro"
 * Both paths send to the same inbox (support@cybersalt.com) with a
 * subject-line prefix so Tim can triage from Gmail; there's no
 * gatekeeping on WHAT free users can submit, just clarity about the
 * response SLA.
 *
 * All submissions carry auto-attached metadata (site URL, cs-mcp-for-j
 * version, Joomla version, PHP version, installed add-ons, Pro state,
 * submitting user identity) so Tim doesn't have to ping-pong for
 * environment details in the first reply.
 */
final class HtmlView extends BaseHtmlView
{
	public string $siteUrl        = '';
	public string $joomlaVersion  = '';
	public string $phpVersion     = '';
	public string $extensionVersion = '';

	/** Logged-in user identity, pre-fills the Reply-To field. */
	public string $userName  = '';
	public string $userEmail = '';

	/** Pro membership state — drives the tier-aware banner. */
	public bool $proActivated  = false;
	public string $proEmail    = '';
	public string $proStatus   = '';

	/**
	 * URL for the Community banner's "consider a Pro membership" hotlink.
	 * Pulled from ProActivationHelper::readPro()['signup_url'] when the
	 * component has a live signup URL cached from cs-release-manager;
	 * falls back to the public extension article on cybersalt.com so the
	 * link always resolves to a useful page even on brand-new installs.
	 * Tim 2026-08-20: earlier version pointed at "Browse MCP Add-Ons"
	 * which doesn't actually sell Pro memberships — this is the fix.
	 */
	public string $proSignupUrl = '';

	/** Installed add-ons list (element strings), attached to the email metadata. */
	public array $installedAddons = [];

	public function display($tpl = null): void
	{
		$app = Factory::getApplication();
		$user = $app->getIdentity();

		$this->siteUrl   = rtrim(Uri::root(), '/');
		$this->userName  = $user ? (string) $user->name  : '';
		$this->userEmail = $user ? (string) $user->email : '';

		$this->joomlaVersion    = (new \Joomla\CMS\Version())->getShortVersion();
		$this->phpVersion       = PHP_VERSION;
		$this->extensionVersion = $this->readExtensionVersion();

		// Pro state via the same helper Dashboard + Catalog use, so all three
		// views agree on the user's tier. refreshIfStale hits cs-release-manager
		// only when the local cache TTL has expired.
		ProActivationHelper::refreshIfStale();
		$pro = ProActivationHelper::readPro();
		$this->proActivated = ProActivationHelper::isActivated();
		$this->proEmail     = (string) ($pro['email'] ?? '');
		$this->proStatus    = (string) ($pro['status'] ?? '');

		// Community-banner hotlink target — the OS Membership Pro plans page
		// on cybersalt.com where Community users actually buy an MCP for J Pro
		// membership. Hardcoded on purpose (Tim 2026-08-20): readPro()'s
		// signup_url on the Package config points at a post-purchase
		// activation-help article, not the plans page, so pulling it dynamically
		// would send buyers to the wrong destination. This is a business URL,
		// not a per-site override surface; if the plans-page URL ever changes,
		// update this constant.
		$this->proSignupUrl = 'https://www.cybersalt.com/membership-plans';

		$this->installedAddons = $this->listInstalledAddons();

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ_SUPPORT_TITLE'), 'cog');

		$toolbar = Toolbar::getInstance('toolbar');
		$toolbar->linkButton('dashboard', 'COM_CSMCPFORJ_TOOLBAR_DASHBOARD')
			->url(Route::_('index.php?option=com_csmcpforj&view=dashboard'))
			->icon('icon-dashboard');
		$toolbar->linkButton('catalog', 'COM_CSMCPFORJ_TOOLBAR_BROWSE_ADDONS')
			->url(Route::_('index.php?option=com_csmcpforj&view=catalog'))
			->icon('icon-cube');
		$toolbar->linkButton('setupguide', 'COM_CSMCPFORJ_TOOLBAR_SETUPGUIDE')
			->url(Route::_('index.php?option=com_csmcpforj&view=setupguide'))
			->icon('icon-help');

		if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			ToolbarHelper::preferences('com_csmcpforj');
		}

		parent::display($tpl);
	}

	/**
	 * Reads the package manifest version so the Support form can attach the
	 * exact installed cs-mcp-for-j version to the email metadata — first
	 * question every support conversation would otherwise ask.
	 */
	private function readExtensionVersion(): string
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select($db->quoteName('manifest_cache'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('element') . ' = ' . $db->quote('pkg_csmcpforj'))
			->where($db->quoteName('type') . ' = ' . $db->quote('package'));
		$db->setQuery($query);

		try {
			$raw = (string) $db->loadResult();
			if ($raw === '') {
				return 'unknown';
			}
			$manifest = json_decode($raw, true);
			return (string) ($manifest['version'] ?? 'unknown');
		} catch (\Throwable $e) {
			return 'unknown';
		}
	}

	/**
	 * Lists installed csmcpforj-family plugins (element strings) so the
	 * support email carries the full add-on inventory. Uses element LIKE
	 * pattern rather than the Dashboard's PLUGIN_TOOL_MAPS static list
	 * because add-ons users install from the catalog aren't in that list.
	 */
	private function listInstalledAddons(): array
	{
		$db    = Factory::getContainer()->get(DatabaseInterface::class);
		$query = $db->getQuery(true)
			->select([$db->quoteName('element'), $db->quoteName('enabled')])
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
			->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
			->where($db->quoteName('element') . ' LIKE ' . $db->quote('csmcpforj%'))
			->order($db->quoteName('element') . ' ASC');
		$db->setQuery($query);

		try {
			$rows = (array) $db->loadObjectList();
		} catch (\Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$out[] = [
				'element' => (string) $row->element,
				'enabled' => (int) $row->enabled === 1,
			];
		}
		return $out;
	}
}
