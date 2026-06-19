<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * Catalog controller.
 *
 * Defaults to displaying the catalog view. The `refresh` task force-refetches
 * catalog.json from the configured endpoint, bypassing the on-disk cache, then
 * redirects back to the catalog view.
 */
final class CatalogController extends BaseController
{
	protected $default_view = 'catalog';

	public function refresh(): void
	{
		$this->checkToken('get');

		// ACL gate: only operators with core.admin (or Super User) on
		// com_csmcpforj can trigger a remote fetch. Without this check, any
		// authenticated admin with the URL could spam the cybersalt.com
		// catalog endpoint and overwrite the on-disk cache.
		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\CatalogModel $model */
		$model  = $this->getModel('Catalog');
		$result = $model->getCatalog(true);

		if (!empty($result['error'])) {
			$this->setMessage(
				Text::sprintf('COM_CSMCPFORJ_CATALOG_REFRESH_ERROR', $result['error']),
				'warning'
			);
		} else {
			$this->setMessage(
				Text::sprintf('COM_CSMCPFORJ_CATALOG_REFRESH_SUCCESS', count($result['addons'] ?? []))
			);
		}

		$this->setRedirect(Route::_('index.php?option=com_csmcpforj&view=catalog', false));
	}

	/**
	 * Enable or disable an installed MCP add-on plugin without leaving the
	 * catalog view. Flips #__extensions.enabled directly — the add-on plugins
	 * have no side-effecting install/uninstall logic gated by the enabled flag,
	 * so a plain UPDATE is sufficient.
	 *
	 * Defensive checks:
	 *   - Token (matches the refresh task's GET-token pattern)
	 *   - core.admin on com_csmcpforj
	 *   - Target row must have element starting with "csmcpforj" — refuses
	 *     to toggle anything else even if the URL passes a random extension_id.
	 */
	public function toggleAddon(): void
	{
		$this->checkToken('get');

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$app         = Factory::getApplication();
		$extensionId = (int) $app->input->getInt('extension_id', 0);
		$desired     = (int) $app->input->getInt('enabled', 0);
		$desired     = $desired === 1 ? 1 : 0;

		$redirectUrl = Route::_('index.php?option=com_csmcpforj&view=catalog', false);

		if ($extensionId <= 0) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_TOGGLE_BAD_ID'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);

		// Look up the row and refuse to touch anything that isn't a csmcpforj
		// add-on plugin. The check is namespaced by the element prefix so
		// future add-ons (csmcpforjdpcal, csmcpforjakeeba, etc.) work
		// without code changes here.
		$lookup = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'element', 'type', 'enabled']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('extension_id') . ' = ' . $extensionId);
		$row = $db->setQuery($lookup)->loadAssoc();

		if (!$row || !str_starts_with((string) $row['element'], 'csmcpforj')) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_TOGGLE_NOT_ADDON'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// Don't disable the core plugin (element exactly "csmcpforj"). The
		// add-ons all have element like "csmcpforj4seo", "csmcpforjrst".
		if ($row['element'] === 'csmcpforj') {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_TOGGLE_REFUSE_CORE'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$update = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('enabled') . ' = ' . $desired)
			->where($db->quoteName('extension_id') . ' = ' . $extensionId);
		$db->setQuery($update)->execute();

		$this->setMessage(Text::sprintf(
			$desired === 1 ? 'COM_CSMCPFORJ_CATALOG_TOGGLE_ENABLED_OK' : 'COM_CSMCPFORJ_CATALOG_TOGGLE_DISABLED_OK',
			$row['element']
		));
		$this->setRedirect($redirectUrl);
	}

	/**
	 * One-click install of a catalog add-on. Fetches the addon's download_url
	 * from the cached catalog, downloads via Joomla's standard InstallerHelper,
	 * unpacks, and hands off to Installer::install — same code path Joomla's
	 * Extensions → Install from URL uses, so all manifest parsing, file copy,
	 * and postflight invocation works exactly as if the user had pasted the
	 * URL into the standard installer.
	 *
	 * Defensive checks:
	 *   - GET token (matches refresh + toggleAddon pattern)
	 *   - core.admin on com_csmcpforj
	 *   - addon_key must resolve to an entry in the catalog (no arbitrary URLs)
	 *   - download_url must be HTTPS
	 */
	public function installAddon(): void
	{
		$this->checkToken('get');

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$app         = Factory::getApplication();
		$addonKey    = trim($app->input->getString('addon_key', ''));
		$redirectUrl = Route::_('index.php?option=com_csmcpforj&view=catalog', false);

		if ($addonKey === '') {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_INSTALL_BAD_KEY'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// Resolve the addon entry from the (cached) catalog. Going through the
		// model rather than trusting any URL on the request keeps the surface
		// to "things the catalog endpoint vouches for".
		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\CatalogModel $model */
		$model   = $this->getModel('Catalog');
		$catalog = $model->getCatalog(false);

		$entry = null;
		foreach ($catalog['addons'] ?? [] as $addon) {
			if (($addon['key'] ?? '') === $addonKey) {
				$entry = $addon;
				break;
			}
		}

		// Admin messages render as HTML in Atum. Any string we substitute that
		// originates from the catalog (a remote endpoint) or the request URL
		// must be HTML-escaped first — otherwise a compromised catalog or a
		// crafted addon_key (delivered as a click on a malicious link) becomes
		// XSS on the admin who hits the controller.
		$safeAddonKey = htmlspecialchars($addonKey, ENT_QUOTES, 'UTF-8');

		if (!$entry) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_UNKNOWN_KEY', $safeAddonKey), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$url           = trim((string) ($entry['download_url'] ?? ''));
		$addonName     = (string) ($entry['name'] ?? $addonKey);
		$safeAddonName = htmlspecialchars($addonName, ENT_QUOTES, 'UTF-8');
		$isPro         = !empty($entry['requires_pro_membership']);

		if ($url === '') {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_NO_URL', $safeAddonName), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// HTTPS-only. The catalog endpoint is HTTPS; downloads should match.
		// Prevents accidental http:// in the catalog from MITM-able pulls.
		if (!preg_match('#^https://#i', $url)) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_URL_NOT_HTTPS', $safeAddonName), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// Pro add-ons need the dlid (Download ID) appended so cs-release-manager's
		// AccessCheckHelper::verifyUpdateAccess gate lets the download through.
		// If Pro membership isn't activated locally, bail with a clear message
		// pointing the user at the dashboard's Pro Activation card.
		if ($isPro) {
			if (!ProActivationHelper::isActivated()) {
				$this->setMessage(
					Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_NEEDS_PRO', $safeAddonName),
					'warning'
				);
				$this->setRedirect(Route::_('index.php?option=com_csmcpforj&view=dashboard', false));
				return;
			}
			$dlid = ProActivationHelper::getDlid();
			if ($dlid !== '') {
				$separator = (strpos($url, '?') === false) ? '?' : '&';
				$url      .= $separator . 'dlid=' . rawurlencode($dlid);
			}
		}

		// 1. Download
		$packageFile = InstallerHelper::downloadPackage($url);

		if (!$packageFile) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_DOWNLOAD_FAILED', $safeAddonName), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$tmpPath = $app->get('tmp_path') . '/' . $packageFile;

		// 2. Unpack
		$package = InstallerHelper::unpack($tmpPath, true);

		if (!$package || empty($package['extractdir'])) {
			InstallerHelper::cleanupInstall($tmpPath, $package['extractdir'] ?? null);
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_UNPACK_FAILED', $safeAddonName), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// 3. Install
		$installer = Installer::getInstance();
		$ok        = $installer->install($package['extractdir']);

		// 4. Cleanup tmp regardless of success
		InstallerHelper::cleanupInstall($tmpPath, $package['extractdir']);

		if (!$ok) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_FAILED', $safeAddonName), 'warning');
		} else {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_INSTALL_SUCCESS', $safeAddonName));
		}

		$this->setRedirect($redirectUrl);
	}

	/**
	 * Uninstall an MCP add-on plugin from the catalog view. Wraps Joomla's
	 * Installer::uninstall() so the standard plugin uninstall path runs
	 * (postflight cleanup, language file removal, etc.).
	 *
	 * Defensive checks:
	 *   - GET token (matches refresh / toggleAddon / installAddon pattern)
	 *   - core.admin on com_csmcpforj
	 *   - Target row must have element starting with "csmcpforj"
	 *   - Refuses to uninstall the core `csmcpforj` plugin (would brick the MCP)
	 */
	public function uninstallAddon(): void
	{
		$this->checkToken('get');

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$app         = Factory::getApplication();
		$extensionId = (int) $app->input->getInt('extension_id', 0);
		$redirectUrl = Route::_('index.php?option=com_csmcpforj&view=catalog', false);

		if ($extensionId <= 0) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_UNINSTALL_BAD_ID'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$db     = Factory::getContainer()->get(DatabaseInterface::class);
		$lookup = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'element', 'type']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('extension_id') . ' = ' . $extensionId);
		$row = $db->setQuery($lookup)->loadAssoc();

		if (!$row || !str_starts_with((string) $row['element'], 'csmcpforj')) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_UNINSTALL_NOT_ADDON'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		if ($row['element'] === 'csmcpforj') {
			$this->setMessage(Text::_('COM_CSMCPFORJ_CATALOG_UNINSTALL_REFUSE_CORE'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		$installer = Installer::getInstance();
		$ok        = $installer->uninstall((string) $row['type'], $extensionId);

		if ($ok) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_UNINSTALL_SUCCESS', $row['element']));
		} else {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_CATALOG_UNINSTALL_FAILED', $row['element']), 'warning');
		}
		$this->setRedirect($redirectUrl);
	}
}
