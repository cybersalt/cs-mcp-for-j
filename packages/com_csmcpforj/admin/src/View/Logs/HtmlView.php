<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\View\Logs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Logs view: what MCP for Joomla did, and why something failed. Follows the
 * Joomla-Brain "Standard Log Viewer" rule (Refresh / Dump / Download CSV /
 * Delete / Clear All, clickable stats cards, a filter for every column,
 * expandable details).
 */
final class HtmlView extends BaseHtmlView
{
	public array $items = [];
	public $pagination;
	public $state;
	public $filterForm;
	public array $activeFilters = [];
	public array $stats = [];
	public bool $tableExists = true;
	public string $level = 'all';

	public function display($tpl = null): void
	{
		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\LogsModel $model */
		$model = $this->getModel();

		$this->tableExists = $model->tableExists();
		$this->level       = ActivityLog::level();

		if ($this->tableExists) {
			$this->items      = $model->getItems() ?: [];
			$this->pagination = $model->getPagination();
			$this->stats      = $model->getStats();
		}

		$this->state         = $model->getState();
		$this->filterForm    = $model->getFilterForm();
		$this->activeFilters = $model->getActiveFilters();

		$this->addToolbar();

		parent::display($tpl);
	}

	private function addToolbar(): void
	{
		$user    = Factory::getApplication()->getIdentity();
		$toolbar = Toolbar::getInstance('toolbar');
		$token   = Session::getFormToken();

		ToolbarHelper::title(Text::_('COM_CSMCPFORJ_LOGS_TITLE'), 'list');

		$toolbar->linkButton('refresh', 'COM_CSMCPFORJ_LOGS_BTN_REFRESH')
			->url(Route::_('index.php?option=com_csmcpforj&view=logs', false))
			->icon('icon-loop');

		if ($this->tableExists) {
			$toolbar->linkButton('dump', 'COM_CSMCPFORJ_LOGS_BTN_DUMP')
				->url(Route::_('index.php?option=com_csmcpforj&task=logs.dump&' . $token . '=1', false))
				->icon('icon-copy')
				->target('_blank');

			$toolbar->linkButton('download', 'COM_CSMCPFORJ_LOGS_BTN_CSV')
				->url(Route::_('index.php?option=com_csmcpforj&task=logs.download&' . $token . '=1', false))
				->icon('icon-download');

			if ($user && $user->authorise('core.delete', 'com_csmcpforj')) {
				$toolbar->delete('logs.delete', 'JTOOLBAR_DELETE')
					->message('JGLOBAL_CONFIRM_DELETE')
					->listCheck(true);

				$toolbar->popupButton('clear-all', 'COM_CSMCPFORJ_LOGS_BTN_CLEAR_ALL')
					->icon('icon-trash')
					->selector('csmcpforjClearLogModal')
					->listCheck(false);
			}
		}

		$toolbar->linkButton('dashboard', 'COM_CSMCPFORJ_TOOLBAR_DASHBOARD')
			->url(Route::_('index.php?option=com_csmcpforj&view=dashboard'))
			->icon('icon-dashboard');

		if ($user && $user->authorise('core.admin', 'com_csmcpforj')) {
			ToolbarHelper::preferences('com_csmcpforj');
		}
	}
}
