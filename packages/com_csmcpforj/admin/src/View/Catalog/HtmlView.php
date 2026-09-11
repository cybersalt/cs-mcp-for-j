<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Catalog;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Catalog view — browse and install MCP add-ons hosted at cybersalt.com.
 *
 * Design intent (lightweight): core ships zero add-on code. The CatalogModel
 * fetches a small JSON list from the cybersalt.com endpoint configured in
 * the component options, caches it for cache_ttl_hours, and renders each
 * entry as a card. Joomla's standard Installer-from-URL does the actual
 * install on demand. No add-on payloads are bundled in the core install.
 */
final class HtmlView extends BaseHtmlView
{
	public string $catalogUrl = '';
	public int $cacheTtlHours = 24;
	public bool $showProUnavailable = true;

	/** @var array<int, array<string, mixed>> */
	public array $addons = [];

	public ?int $fetchedAt = null;
	public string $catalogSource = 'empty';
	public string $sourceUrl = '';
	public ?string $catalogError = null;

	/**
	 * URL to the public "independent development" disclosure page on
	 * cybersalt.com. Catalog cards for add-ons that wrap a third-party
	 * extension link to this page so end users can verify that cs-mcp-for-j is
	 * built independently of the vendor of the extension it wraps. Surfaced
	 * from the catalog JSON's top-level `independence_notice_url` field, with
	 * a sensible default if the field is missing.
	 */
	public string $independenceNoticeUrl = '';

	public function display($tpl = null): void
	{
		$params = ComponentHelper::getParams('com_csmcpforj');
		$this->catalogUrl         = rtrim((string) $params->get('catalog_url', 'https://cybersalt.com/cs-mcp-for-j/'), '/');
		$this->cacheTtlHours      = max(1, min(168, (int) $params->get('cache_ttl_hours', 24)));
		$this->showProUnavailable = (bool) $params->get('catalog_show_pro_unavailable', 1);

		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\CatalogModel $model */
		$model   = $this->getModel('Catalog');
		$catalog = $model->getCatalog(false);

		$this->addons        = $catalog['addons'] ?? [];
		$this->fetchedAt     = $catalog['fetched_at'] ?? null;
		$this->catalogSource = (string) ($catalog['source'] ?? 'empty');
		$this->sourceUrl     = (string) ($catalog['source_url'] ?? ($this->catalogUrl . '/catalog.json'));
		$this->catalogError  = $catalog['error'] ?? null;
		$this->independenceNoticeUrl = (string) ($catalog['independence_notice_url']
			?? 'https://www.cybersalt.com/extensions/mcp-for-j-independent-development');

		// Enrich each Pro-tier addon with PER-ADD-ON entitlement so the template
		// can decide between the Install button (this account owns this add-on —
		// à-la-carte OR All-Access) and the locked "Get it" state (not entitled).
		// getEntitledElements() is the list cs-release-manager's verifyaccess
		// returned for the linked account (v1.11.5+); free add-ons are always in
		// it. Replaces the old single isActivated() boolean that gated every Pro
		// add-on together and told à-la-carte buyers they had no membership (#21).
		// Keep entitlement fresh even when the user opens the catalog without
		// hitting the Dashboard first (refreshIfStale is throttled + idempotent
		// per request). Then self-heal: if we're linked but have no entitlement
		// list yet (verified under a pre-entitlement build, or first load after
		// upgrade), force one refresh so owned add-ons don't wrongly show "Get it".
		ProActivationHelper::refreshIfStale();
		$entitled = ProActivationHelper::getEntitledElements();
		if (empty($entitled) && ProActivationHelper::isLinked()) {
			ProActivationHelper::forceRefresh();
			$entitled = ProActivationHelper::getEntitledElements();
		}
		foreach ($this->addons as $i => $addon) {
			$element = (string) ($addon['addon_extension']['element'] ?? '');
			$this->addons[$i]['has_pro_membership'] = !empty($addon['requires_pro_membership'])
				&& $element !== '' && in_array($element, $entitled, true);
		}

		if (!$this->showProUnavailable) {
			$this->addons = array_values(array_filter(
				$this->addons,
				static fn(array $addon): bool => empty($addon['requires_pro_membership']) || !empty($addon['has_pro_membership'])
			));
		}

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ_CATALOG_TITLE'), 'cog');

		$toolbar = Toolbar::getInstance('toolbar');
		$toolbar->linkButton('dashboard', 'COM_CSMCPFORJ_TOOLBAR_DASHBOARD')
			->url(Route::_('index.php?option=com_csmcpforj&view=dashboard'))
			->icon('icon-dashboard');
		$toolbar->linkButton('setupguide', 'COM_CSMCPFORJ_TOOLBAR_SETUPGUIDE')
			->url(Route::_('index.php?option=com_csmcpforj&view=setupguide'))
			->icon('icon-help');
		$toolbar->linkButton('support', 'COM_CSMCPFORJ_TOOLBAR_SUPPORT')
			->url(Route::_('index.php?option=com_csmcpforj&view=support'))
			->icon('icon-envelope');

		ToolbarHelper::preferences('com_csmcpforj');

		parent::display($tpl);
	}
}
