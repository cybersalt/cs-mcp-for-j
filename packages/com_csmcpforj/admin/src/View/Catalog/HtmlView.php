<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Catalog;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
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

		if (!$this->showProUnavailable) {
			$this->addons = array_values(array_filter(
				$this->addons,
				static fn(array $addon): bool => empty($addon['requires_pro_membership']) || !empty($addon['has_pro_membership'])
			));
		}

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ_CATALOG_TITLE'), 'cog');
		ToolbarHelper::preferences('com_csmcpforj');

		parent::display($tpl);
	}
}
