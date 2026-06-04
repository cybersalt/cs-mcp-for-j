<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

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
}
