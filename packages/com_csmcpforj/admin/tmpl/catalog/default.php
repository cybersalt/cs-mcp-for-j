<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/** @var \Cybersalt\Component\Csmcpforj\Administrator\View\Catalog\HtmlView $this */

$endpoint   = htmlspecialchars($this->sourceUrl, ENT_QUOTES, 'UTF-8');
$optionsUrl = Route::_('index.php?option=com_config&view=component&component=com_csmcpforj');
$refreshUrl = Route::_('index.php?option=com_csmcpforj&task=catalog.refresh&' . Session::getFormToken() . '=1');

$sourceLabels = [
	'remote'   => Text::_('COM_CSMCPFORJ_CATALOG_SOURCE_REMOTE'),
	'cache'    => Text::_('COM_CSMCPFORJ_CATALOG_SOURCE_CACHE'),
	'fallback' => Text::_('COM_CSMCPFORJ_CATALOG_SOURCE_FALLBACK'),
	'empty'    => Text::_('COM_CSMCPFORJ_CATALOG_SOURCE_EMPTY'),
];
$sourceLabel = $sourceLabels[$this->catalogSource] ?? $this->catalogSource;

$fetchedLine = '';
if ($this->fetchedAt) {
	$fetchedLine = Text::sprintf(
		'COM_CSMCPFORJ_CATALOG_FETCHED_LINE',
		HTMLHelper::_('date', '@' . $this->fetchedAt, Text::_('DATE_FORMAT_LC2'))
	);
}
?>
<div class="container-fluid">
	<div class="row">
		<div class="col-12">

			<div class="card mb-3">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
						<div>
							<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_INTRO_HEADING'); ?></h3>
							<p class="card-text mb-2">
								<?php echo Text::_('COM_CSMCPFORJ_CATALOG_INTRO_BODY'); ?>
							</p>
						</div>
						<div>
							<a href="<?php echo $refreshUrl; ?>" class="btn btn-primary">
								<span class="icon-loop" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_CATALOG_REFRESH_BUTTON'); ?>
							</a>
						</div>
					</div>
					<p class="card-text mb-0">
						<small class="text-body-secondary">
							<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_ENDPOINT_LINE', '<code>' . $endpoint . '</code>'); ?>
							<a href="<?php echo $optionsUrl; ?>" class="ms-2"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_CHANGE_ENDPOINT'); ?></a>
							<span class="ms-2 badge bg-secondary"><?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
							<?php if ($fetchedLine) : ?>
								<span class="ms-2"><?php echo $fetchedLine; ?></span>
							<?php endif; ?>
						</small>
					</p>
				</div>
			</div>

			<?php if ($this->catalogError) : ?>
				<div class="alert alert-warning">
					<strong><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FETCH_WARNING'); ?></strong>
					<?php echo htmlspecialchars($this->catalogError, ENT_QUOTES, 'UTF-8'); ?>
				</div>
			<?php endif; ?>

			<?php if (empty($this->addons)) : ?>
				<div class="alert alert-info">
					<h4 class="alert-heading"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_EMPTY_HEADING'); ?></h4>
					<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_EMPTY_BODY'); ?></p>
				</div>
			<?php else : ?>
				<div class="row row-cols-1 row-cols-md-2 g-3">
					<?php foreach ($this->addons as $addon) : ?>
						<?php
						$name        = htmlspecialchars((string) ($addon['name'] ?? ''), ENT_QUOTES, 'UTF-8');
						$desc        = htmlspecialchars((string) ($addon['short_description'] ?? ''), ENT_QUOTES, 'UTF-8');
						$version     = htmlspecialchars((string) ($addon['version'] ?? ''), ENT_QUOTES, 'UTF-8');
						$tier        = !empty($addon['requires_pro_membership']) ? 'pro' : 'free';
						$tierLabel   = $tier === 'pro' ? Text::_('COM_CSMCPFORJ_CATALOG_TIER_PRO') : Text::_('COM_CSMCPFORJ_CATALOG_TIER_FREE');
						$tierClass   = $tier === 'pro' ? 'bg-warning text-dark' : 'bg-success';
						?>
						<div class="col">
							<div class="card h-100">
								<div class="card-body">
									<div class="d-flex justify-content-between align-items-start gap-2">
										<h5 class="card-title mb-1"><?php echo $name; ?></h5>
										<span class="badge <?php echo $tierClass; ?>"><?php echo htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8'); ?></span>
									</div>
									<?php if ($version) : ?>
										<p class="text-body-secondary mb-2"><small>v<?php echo $version; ?></small></p>
									<?php endif; ?>
									<p class="card-text"><?php echo $desc; ?></p>
									<!-- Phase 1d/1e fill in state badges + install button -->
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</div>
	</div>
</div>
