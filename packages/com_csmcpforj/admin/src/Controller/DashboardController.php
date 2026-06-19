<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Dashboard controller — handles the Pro Membership activate / deactivate
 * actions invoked from the dashboard's Pro Activation card.
 *
 * Display tasks belong to DisplayController; this exists separately so the
 * routes are `task=dashboard.activatePro` and `task=dashboard.deactivatePro`,
 * which read better in URLs than `task=display.activatePro`.
 */
final class DashboardController extends BaseController
{
	protected $default_view = 'dashboard';

	/**
	 * POST handler invoked by the "Activate Pro" form. Runs the two-step
	 * register + linkEmail flow against cs-release-manager. On success, the
	 * helper has persisted the email + email_hash + status='active'. On any
	 * failure, surfaces the friendly error message + (if cs-release-manager
	 * sent one) a renewal URL the user can click.
	 */
	public function activatePro(): void
	{
		$this->checkToken();

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$app         = Factory::getApplication();
		$email       = trim((string) $app->input->getString('email', ''));
		$redirectUrl = Route::_('index.php?option=com_csmcpforj&view=dashboard', false);

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_PRO_ACTIVATE_BAD_EMAIL'), 'warning');
			$this->setRedirect($redirectUrl);
			return;
		}

		// Step 1: register the installation against a real Pro Package (not
		// the free pkg_csmcpforj core), so the linkEmail + checkupdate
		// round-trip below actually exercises cs-release-manager's user_groups
		// gate. Using the free anchor would silently pass any email through,
		// which is what shipped initially and produced the false-positive
		// "Active" status Tim flagged. The helper's constant is the source of
		// truth for which Pro Package we anchor to.
		$registerResult = ProActivationHelper::registerInstallation(ProActivationHelper::getActivationAnchorElement());
		if (!$registerResult['ok']) {
			$this->setMessage(
				Text::sprintf('COM_CSMCPFORJ_PRO_ACTIVATE_REGISTER_FAILED', $registerResult['error'] ?? ''),
				'warning'
			);
			$this->setRedirect($redirectUrl);
			return;
		}

		// Step 2: link the email + verify membership state. linkEmail() calls
		// cs-release-manager's api.verifyaccess endpoint internally and
		// returns one of the 4 states (active / lapsed / not_a_member /
		// blacklisted) or 'denied' for terminal errors. We surface the result
		// as a message tier matching the dashboard card's visual treatment.
		$linkResult = ProActivationHelper::linkEmail($email);
		$state      = (string) ($linkResult['state'] ?? 'denied');
		$srvMessage = (string) ($linkResult['message'] ?? '');

		switch ($state) {
			case 'active':
				$this->setMessage(Text::sprintf('COM_CSMCPFORJ_PRO_ACTIVATE_SUCCESS', $email), 'message');
				break;
			case 'lapsed':
			case 'not_a_member':
			case 'blacklisted':
				// 'notice' tier — Joomla renders as a blue/info alert, which is
				// the right register for "we got an answer, the answer is no, but
				// the dashboard card now has actionable next steps". 'warning'
				// would feel like a system error.
				$this->setMessage($srvMessage !== '' ? $srvMessage : Text::_('COM_CSMCPFORJ_PRO_ACTIVATE_LINK_FAILED'), 'notice');
				break;
			default:
				$this->setMessage(
					Text::sprintf('COM_CSMCPFORJ_PRO_ACTIVATE_LINK_FAILED', $srvMessage),
					'warning'
				);
		}
		$this->setRedirect($redirectUrl);
	}

	/**
	 * Clear the local Pro activation state. Does NOT contact cs-release-manager
	 * — the #__csrm_installations row on cybersalt.com is intentionally left
	 * in place so reactivation with the same email works without a fresh
	 * register call. To revoke server-side, the operator uses the customer
	 * portal on cybersalt.com.
	 */
	public function deactivatePro(): void
	{
		$this->checkToken();

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		ProActivationHelper::deactivate();
		$this->setMessage(Text::_('COM_CSMCPFORJ_PRO_DEACTIVATE_SUCCESS'));
		$this->setRedirect(Route::_('index.php?option=com_csmcpforj&view=dashboard', false));
	}

	/**
	 * Bypass Joomla's #__updates table cache and immediately re-poll the
	 * cs-release-manager update XML endpoint for every cs-mcp-for-j-related
	 * extension (core package + every csmcpforj* add-on plugin installed).
	 *
	 * Why this exists: Joomla's "Find Updates" honors a per-extension cache
	 * controlled by `com_installer` Options (default 24h). Until that TTL
	 * elapses, "Find Updates" silently returns stale data even after we
	 * publish a brand-new release in cs-release-manager. This button takes
	 * the receiving site out of that wait — it asks Joomla's Updater directly
	 * with `cacheTimeout=0` so the next page-load already shows the new
	 * version in Components → Joomla Update / Extensions → Update.
	 *
	 * Scope: only extensions whose update site URL points at cybersalt.com's
	 * release-manager. We don't go behind the operator's back and re-poll
	 * every other update site on the site.
	 */
	public function checkUpdatesNow(): void
	{
		$this->checkToken();

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		$query = $db->getQuery(true)
			->select('DISTINCT ' . $db->quoteName('use.extension_id'))
			->from($db->quoteName('#__update_sites_extensions', 'use'))
			->innerJoin($db->quoteName('#__update_sites', 'us') . ' ON ' . $db->quoteName('us.update_site_id') . ' = ' . $db->quoteName('use.update_site_id'))
			->where($db->quoteName('us.enabled') . ' = 1')
			->where($db->quoteName('us.location') . ' LIKE ' . $db->quote('%cybersalt.com%task=api.updatexml%'));

		$extensionIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
		$redirect = Route::_('index.php?option=com_csmcpforj&view=dashboard', false);

		if (empty($extensionIds)) {
			$this->setMessage(Text::_('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_NONE'), 'warning');
			$this->setRedirect($redirect);
			return;
		}

		// Wipe stale cached rows for these extensions so the Updater's TTL check
		// is guaranteed to fall through and re-poll. Belt-and-braces alongside
		// the cacheTimeout=0 argument below.
		$placeholders = implode(',', array_fill(0, count($extensionIds), '?'));
		$delete = $db->getQuery(true)
			->delete($db->quoteName('#__updates'))
			->where($db->quoteName('extension_id') . ' IN (' . $placeholders . ')');
		$db->setQuery($delete);
		foreach ($extensionIds as $i => $id) {
			$db->bind($i + 1, $id, \Joomla\Database\ParameterType::INTEGER);
		}
		$db->execute();

		// Reset each update site's last_check_timestamp so Joomla's Updater
		// won't skip the poll for "still inside cache window".
		$reset = $db->getQuery(true)
			->update($db->quoteName('#__update_sites'))
			->set($db->quoteName('last_check_timestamp') . ' = 0')
			->where($db->quoteName('location') . ' LIKE ' . $db->quote('%cybersalt.com%task=api.updatexml%'));
		$db->setQuery($reset)->execute();

		// Force the actual fetch via Joomla's Updater. cacheTimeout=0 means
		// "ignore any remaining time-based throttle, hit the URL now".
		try {
			$updater = \Joomla\CMS\Updater\Updater::getInstance();
			$updater->findUpdates($extensionIds, 0, \Joomla\CMS\Updater\Updater::STABILITY_STABLE);
		} catch (\Throwable $e) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_ERROR', $e->getMessage()), 'error');
			$this->setRedirect($redirect);
			return;
		}

		// Count how many updates actually surfaced so we can tell the user.
		$countQuery = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->quoteName('#__updates'))
			->where($db->quoteName('extension_id') . ' IN (' . $placeholders . ')');
		$db->setQuery($countQuery);
		foreach ($extensionIds as $i => $id) {
			$db->bind($i + 1, $id, \Joomla\Database\ParameterType::INTEGER);
		}
		$count = (int) $db->loadResult();

		if ($count > 0) {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_FOUND', $count, count($extensionIds)));
		} else {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_UPTODATE', count($extensionIds)));
		}

		$this->setRedirect($redirect);
	}

	/**
	 * Force a fresh verifyaccess round-trip to cs-release-manager, bypassing
	 * the recheck_seconds TTL. Invoked by the dashboard's "Refresh Membership
	 * Status" button so a user who just renewed their membership on cybersalt.com
	 * can see the new state immediately rather than waiting up to a day for
	 * the next throttled recheck.
	 */
	public function refreshMembership(): void
	{
		$this->checkToken();

		if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_csmcpforj')) {
			throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$state    = ProActivationHelper::forceRefresh();
		$redirect = Route::_('index.php?option=com_csmcpforj&view=dashboard', false);

		if ($state === '') {
			$this->setMessage(Text::_('COM_CSMCPFORJ_PRO_REFRESH_NOTHING'), 'warning');
		} elseif ($state === 'active') {
			$this->setMessage(Text::_('COM_CSMCPFORJ_PRO_REFRESH_ACTIVE'));
		} else {
			$this->setMessage(Text::sprintf('COM_CSMCPFORJ_PRO_REFRESH_DONE', $state), 'info');
		}

		$this->setRedirect($redirect);
	}
}
