<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Logs toolbar actions: Dump (plain text), Download CSV, Delete selected,
 * Clear All. Dump and CSV export the rows matching the current filters.
 */
final class LogsController extends BaseController
{
	private const VIEW_URL = 'index.php?option=com_csmcpforj&view=logs';

	public function dump(): void
	{
		$this->checkToken('get');

		if (!$this->allowed('core.manage')) {
			return;
		}

		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\LogsModel $model */
		$model = $this->getModel('Logs', 'Administrator');
		$text  = $model->getTextDump();
		$app   = Factory::getApplication();

		$app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
		$app->setHeader('Cache-Control', 'no-store', true);
		$app->setHeader('X-Content-Type-Options', 'nosniff', true);
		$app->sendHeaders();
		echo $text;
		$app->close();
	}

	public function download(): void
	{
		$this->checkToken('get');

		if (!$this->allowed('core.manage')) {
			return;
		}

		/** @var \Cybersalt\Component\Csmcpforj\Administrator\Model\LogsModel $model */
		$model = $this->getModel('Logs', 'Administrator');
		$csv   = $model->getCsv();
		$app   = Factory::getApplication();

		$app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
		$app->setHeader('Content-Disposition', 'attachment; filename="csmcpforj-log-' . gmdate('Ymd-His') . '.csv"', true);
		$app->setHeader('Cache-Control', 'no-store', true);
		$app->sendHeaders();
		echo "\xEF\xBB\xBF" . $csv;
		$app->close();
	}

	public function delete(): void
	{
		$this->checkToken();

		if (!$this->allowed('core.delete')) {
			return;
		}

		$ids = array_values(array_filter(
			array_map('intval', (array) $this->input->get('cid', [], 'array')),
			static fn (int $id): bool => $id > 0
		));

		if ($ids === []) {
			$this->setRedirect(Route::_(self::VIEW_URL, false), Text::_('JLIB_HTML_PLEASE_MAKE_A_SELECTION_FROM_THE_LIST'), 'warning');

			return;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName(ActivityLog::TABLE))
				->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
		)->execute();

		$this->setRedirect(Route::_(self::VIEW_URL, false), Text::plural('COM_CSMCPFORJ_LOGS_N_DELETED', count($ids)));
	}

	public function purge(): void
	{
		$this->checkToken();

		if (!$this->allowed('core.delete')) {
			return;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$db->setQuery('TRUNCATE TABLE ' . $db->quoteName(ActivityLog::TABLE))->execute();

		$this->setRedirect(Route::_(self::VIEW_URL, false), Text::_('COM_CSMCPFORJ_LOGS_PURGED'));
	}

	private function allowed(string $action): bool
	{
		$user = Factory::getApplication()->getIdentity();

		if ($user !== null && $user->authorise($action, 'com_csmcpforj')) {
			return true;
		}

		$this->setRedirect(Route::_(self::VIEW_URL, false), Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 'error');

		return false;
	}
}
