<?php

/**
 * Logs view. Joomla-Brain "Standard Log Viewer": clickable stats cards,
 * searchtools filters, colour-coded status badges, expandable details in a
 * bordered <pre>, an empty state, and a Bootstrap modal for Clear All.
 * Dark-mode safe: Bootstrap utilities and Atum variables only.
 *
 * @var \Cybersalt\Component\Csmcpforj\Administrator\View\Logs\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

HTMLHelper::_('behavior.multiselect');
HTMLHelper::_('bootstrap.modal');

$listOrder = $this->escape((string) $this->state->get('list.ordering'));
$listDirn  = $this->escape((string) $this->state->get('list.direction'));
$base      = Route::_('index.php?option=com_csmcpforj&view=logs', false);
$filterUrl = static fn (array $f): string => $base . '&' . http_build_query(['filter' => $f + ['search' => '', 'status' => '', 'source' => '', 'event' => '', 'since' => '', 'request_id' => '', 'user_id' => '']]);

$badge = static function (string $status): string {
	$class = match ($status) {
		'success' => 'bg-success',
		'info'    => 'bg-info text-dark',
		'warning' => 'bg-warning text-dark',
		'error'   => 'bg-danger',
		default   => 'bg-secondary',
	};

	return '<span class="badge ' . $class . '">' . htmlspecialchars(Text::_('COM_CSMCPFORJ_LOGS_STATUS_' . strtoupper($status)), ENT_QUOTES, 'UTF-8') . '</span>';
};

$cards = [
	['total', 'COM_CSMCPFORJ_LOGS_STAT_TOTAL', $filterUrl([]), ''],
	['last24h', 'COM_CSMCPFORJ_LOGS_STAT_LAST24H', $filterUrl(['since' => '24h']), ''],
	['success', 'COM_CSMCPFORJ_LOGS_STATUS_SUCCESS', $filterUrl(['status' => 'success']), 'text-success'],
	['info', 'COM_CSMCPFORJ_LOGS_STATUS_INFO', $filterUrl(['status' => 'info']), 'text-info'],
	['warning', 'COM_CSMCPFORJ_LOGS_STATUS_WARNING', $filterUrl(['status' => 'warning']), 'text-warning'],
	['error', 'COM_CSMCPFORJ_LOGS_STATUS_ERROR', $filterUrl(['status' => 'error']), 'text-danger'],
	['oauth', 'COM_CSMCPFORJ_LOGS_STAT_OAUTH', $filterUrl(['source' => 'oauth']), ''],
	['toolcalls', 'COM_CSMCPFORJ_LOGS_STAT_TOOLCALLS', $filterUrl(['event' => 'tools/call']), ''],
];
?>
<style>
.csmcpforj-logs .csmcpforj-stat { transition: transform .12s ease, box-shadow .12s ease; }
.csmcpforj-logs .csmcpforj-stat:hover { transform: translateY(-2px); box-shadow: 0 .25rem .75rem rgba(0,0,0,.15); }
.csmcpforj-logs pre.csmcpforj-log-details { max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
.csmcpforj-logs .csmcpforj-callout { background: var(--body-bg, var(--bs-body-bg)); border: 1px solid var(--bs-border-color); border-left: 4px solid #4dd0e1; border-radius: .25rem; padding: .75rem 1rem; margin-bottom: 1rem; }
.csmcpforj-logs .csmcpforj-callout strong.csmcpforj-callout-title { color: #4dd0e1; }
.csmcpforj-logs .csmcpforj-callout-warning { border-left-color: #ffc107; }
.csmcpforj-logs .csmcpforj-callout-warning strong.csmcpforj-callout-title { color: #ffc107; }
</style>

<div class="csmcpforj-logs">

	<?php if (!$this->tableExists) : ?>
		<div class="csmcpforj-callout csmcpforj-callout-warning">
			<strong class="csmcpforj-callout-title"><?php echo Text::_('COM_CSMCPFORJ_LOGS_NO_TABLE_TITLE'); ?></strong>
			<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_LOGS_NO_TABLE_BODY'); ?></p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ($this->level !== 'all') : ?>
		<div class="csmcpforj-callout<?php echo $this->level === 'off' ? ' csmcpforj-callout-warning' : ''; ?>">
			<strong class="csmcpforj-callout-title"><?php echo Text::_('COM_CSMCPFORJ_LOGS_LEVEL_' . strtoupper($this->level) . '_TITLE'); ?></strong>
			<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_LOGS_LEVEL_' . strtoupper($this->level) . '_BODY'); ?></p>
		</div>
	<?php endif; ?>

	<div class="row row-cols-2 row-cols-md-4 row-cols-xl-8 g-2 mb-3">
		<?php foreach ($cards as [$key, $label, $href, $tone]) : ?>
			<div class="col">
				<a href="<?php echo $this->escape($href); ?>" class="card csmcpforj-stat text-decoration-none h-100">
					<div class="card-body text-center py-2">
						<div class="h3 mb-0 <?php echo $tone; ?>"><?php echo (int) ($this->stats[$key] ?? 0); ?></div>
						<div class="small text-body-secondary"><?php echo Text::_($label); ?></div>
					</div>
				</a>
			</div>
		<?php endforeach; ?>
	</div>

	<form action="<?php echo $base; ?>" method="post" name="adminForm" id="adminForm">
		<?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

		<?php if (empty($this->items)) : ?>
			<div class="alert alert-info">
				<span class="icon-info-circle" aria-hidden="true"></span>
				<?php echo Text::_($this->activeFilters ? 'COM_CSMCPFORJ_LOGS_EMPTY_FILTERED' : 'COM_CSMCPFORJ_LOGS_EMPTY'); ?>
			</div>
		<?php else : ?>
			<table class="table" id="logList">
				<caption class="visually-hidden"><?php echo Text::_('COM_CSMCPFORJ_LOGS_TITLE'); ?></caption>
				<thead>
					<tr>
						<td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
						<th scope="col" class="text-nowrap"><?php echo HTMLHelper::_('searchtools.sort', 'COM_CSMCPFORJ_LOGS_COL_TIME', 'a.id', $listDirn, $listOrder); ?></th>
						<th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_CSMCPFORJ_LOGS_COL_STATUS', 'a.status', $listDirn, $listOrder); ?></th>
						<th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_CSMCPFORJ_LOGS_COL_EVENT', 'a.event', $listDirn, $listOrder); ?></th>
						<th scope="col"><?php echo Text::_('COM_CSMCPFORJ_LOGS_COL_SUMMARY'); ?></th>
						<th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_CSMCPFORJ_LOGS_COL_USER', 'u.username', $listDirn, $listOrder); ?></th>
						<th scope="col" class="text-end text-nowrap"><?php echo HTMLHelper::_('searchtools.sort', 'COM_CSMCPFORJ_LOGS_COL_ELAPSED', 'a.elapsed_ms', $listDirn, $listOrder); ?></th>
						<th scope="col" class="w-1 text-end"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($this->items as $i => $item) : ?>
						<tr class="row<?php echo $i % 2; ?>">
							<td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, $item->id); ?></td>
							<td class="text-nowrap small">
								<?php echo HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC6')); ?>
								<br>
								<a class="text-body-secondary font-monospace" href="<?php echo $this->escape($filterUrl(['request_id' => $item->request_id])); ?>" title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_LOGS_REQUEST_TIP')); ?>">
									<?php echo $this->escape($item->request_id); ?>
								</a>
							</td>
							<td><?php echo $badge((string) $item->status); ?></td>
							<td class="small">
								<a href="<?php echo $this->escape($filterUrl(['event' => $item->event])); ?>"><code><?php echo $this->escape($item->event); ?></code></a>
								<?php if ((string) $item->subject !== '') : ?>
									<br><span class="text-body-secondary"><?php echo $this->escape($item->subject); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ((string) $item->details === '') : ?>
									<?php echo $this->escape($item->summary); ?>
								<?php else : ?>
									<details>
										<summary><?php echo $this->escape($item->summary); ?></summary>
										<pre class="border rounded p-2 mt-2 mb-0 small csmcpforj-log-details"><?php echo $this->escape($item->details); ?></pre>
									</details>
								<?php endif; ?>
								<?php if ((string) $item->ip !== '' || (string) $item->client_id !== '') : ?>
									<div class="small text-body-secondary mt-1">
										<?php if ((string) $item->ip !== '') : ?>IP <?php echo $this->escape($item->ip); ?><?php endif; ?>
										<?php if ((string) $item->client_id !== '') : ?> &middot; <?php echo Text::_('COM_CSMCPFORJ_LOGS_APP'); ?> <code><?php echo $this->escape($item->client_id); ?></code><?php endif; ?>
									</div>
								<?php endif; ?>
							</td>
							<td class="small">
								<?php if ((int) $item->user_id > 0) : ?>
									<a href="<?php echo $this->escape($filterUrl(['user_id' => (string) (int) $item->user_id])); ?>">
										<?php echo $this->escape((string) ($item->username ?? ('#' . (int) $item->user_id))); ?>
									</a>
								<?php else : ?>
									<span class="text-body-secondary">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="text-end small text-nowrap"><?php echo $item->elapsed_ms === null ? '&mdash;' : (int) $item->elapsed_ms . ' ms'; ?></td>
							<td class="text-end small"><?php echo (int) $item->id; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php echo $this->pagination->getListFooter(); ?>
		<?php endif; ?>

		<input type="hidden" name="task" value="">
		<input type="hidden" name="boxchecked" value="0">
		<?php echo HTMLHelper::_('form.token'); ?>
	</form>

	<p class="small text-body-secondary mt-3"><?php echo Text::_('COM_CSMCPFORJ_LOGS_FOOTER'); ?></p>
</div>

<?php
$modalBody = '<div class="p-3"><p>' . Text::_('COM_CSMCPFORJ_LOGS_CLEAR_MODAL_BODY') . '</p>'
	. '<form method="post" action="' . Route::_('index.php?option=com_csmcpforj&task=logs.purge') . '" class="d-flex justify-content-end gap-2">'
	. '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . Text::_('JCANCEL') . '</button>'
	. '<button type="submit" class="btn btn-danger text-white">' . Text::_('COM_CSMCPFORJ_LOGS_CLEAR_MODAL_CONFIRM') . '</button>'
	. HTMLHelper::_('form.token')
	. '</form></div>';

echo HTMLHelper::_('bootstrap.renderModal', 'csmcpforjClearLogModal', [
	'title'       => Text::_('COM_CSMCPFORJ_LOGS_CLEAR_MODAL_TITLE'),
	'backdrop'    => 'static',
	'keyboard'    => false,
	'closeButton' => true,
], $modalBody);
