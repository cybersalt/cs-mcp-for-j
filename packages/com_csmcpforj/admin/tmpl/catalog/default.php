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
				<div class="js-stools clearfix mb-3">
					<div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
						<div class="js-stools-container-bar">
							<label for="catalog-search" class="visually-hidden">
								<?php echo Text::_('JSEARCH_FILTER'); ?>
							</label>
							<div class="btn-group">
								<input type="search"
									name="catalog_search"
									id="catalog-search"
									class="js-stools-search-string form-control"
									placeholder="<?php echo Text::_('JSEARCH_FILTER'); ?>"
									autocomplete="off">
								<button type="button" class="btn btn-primary" id="catalog-search-trigger"
									aria-label="<?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?>">
									<span class="icon-search" aria-hidden="true"></span>
								</button>
							</div>
						</div>
						<div class="js-stools-container-list d-flex gap-2">
							<button type="button" class="btn btn-primary js-stools-btn-filter" id="catalog-toggle-filters"
								aria-expanded="false">
								<?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_OPTIONS'); ?>
								<span class="icon-caret-down" aria-hidden="true"></span>
							</button>
							<button type="button" class="btn btn-secondary" id="catalog-clear-filters">
								<?php echo Text::_('JSEARCH_FILTER_CLEAR'); ?>
							</button>
						</div>
					</div>

					<div class="js-stools-container-filters d-none d-flex flex-wrap gap-2" id="catalog-filters-panel">
						<div class="js-stools-field-filter" style="flex: 0 1 auto; min-width: 180px;">
							<select class="form-select catalog-filter-select" id="filter-tier" aria-label="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_TIER_LABEL'); ?>">
								<option value=""><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_TIER_PLACEHOLDER'); ?></option>
								<option value="free"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_TIER_FREE'); ?></option>
								<option value="pro"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_TIER_PRO'); ?></option>
							</select>
						</div>

						<div class="js-stools-field-filter" style="flex: 0 1 auto; min-width: 180px;">
							<select class="form-select catalog-filter-select" id="filter-installed" aria-label="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_INSTALLED_LABEL'); ?>">
								<option value=""><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_INSTALLED_PLACEHOLDER'); ?></option>
								<option value="yes"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_INSTALLED_YES'); ?></option>
								<option value="no"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_INSTALLED_NO'); ?></option>
							</select>
						</div>

						<div class="js-stools-field-filter" style="flex: 0 1 auto; min-width: 180px;">
							<select class="form-select catalog-filter-select" id="filter-enabled" aria-label="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_ENABLED_LABEL'); ?>">
								<option value=""><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_ENABLED_PLACEHOLDER'); ?></option>
								<option value="yes"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_ENABLED_YES'); ?></option>
								<option value="no"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_ENABLED_NO'); ?></option>
							</select>
						</div>

						<div class="js-stools-field-filter" style="flex: 0 1 auto; min-width: 180px;">
							<select class="form-select catalog-filter-select" id="filter-update" aria-label="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_UPDATE_LABEL'); ?>">
								<option value=""><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_UPDATE_PLACEHOLDER'); ?></option>
								<option value="yes"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_UPDATE_YES'); ?></option>
								<option value="no"><?php echo Text::_('COM_CSMCPFORJ_CATALOG_FILTER_UPDATE_NO'); ?></option>
							</select>
						</div>
					</div>
				</div>

				<div class="row row-cols-1 row-cols-md-2 g-3" id="catalog-cards">
					<?php foreach ($this->addons as $addon) : ?>
						<?php
						$name             = htmlspecialchars((string) ($addon['name'] ?? ''), ENT_QUOTES, 'UTF-8');
						$desc             = htmlspecialchars((string) ($addon['short_description'] ?? ''), ENT_QUOTES, 'UTF-8');
						$version          = htmlspecialchars((string) ($addon['version'] ?? ''), ENT_QUOTES, 'UTF-8');
						$tier             = !empty($addon['requires_pro_membership']) ? 'pro' : 'free';
						$tierLabel        = $tier === 'pro' ? Text::_('COM_CSMCPFORJ_CATALOG_TIER_PRO') : Text::_('COM_CSMCPFORJ_CATALOG_TIER_FREE');
						$tierClass        = $tier === 'pro' ? 'bg-warning text-dark' : 'bg-success';
						$isInstalled      = !empty($addon['installed']);
						$isEnabled        = !empty($addon['enabled']);
						$extensionId      = (int) ($addon['extension_id'] ?? 0);
						$installedVersion = htmlspecialchars((string) ($addon['installed_version'] ?? ''), ENT_QUOTES, 'UTF-8');
						$addonKey         = (string) ($addon['key'] ?? '');
						$downloadUrl      = (string) ($addon['download_url'] ?? '');
						$isSuperseded     = !empty($addon['superseded']);
						$supersededByName = htmlspecialchars((string) ($addon['superseded_by_name'] ?? ''), ENT_QUOTES, 'UTF-8');

						// Build the install URL once if we have a key + URL to send to.
						$installUrl = '';
						if ($addonKey !== '' && $downloadUrl !== '') {
							$installUrl = Route::_(
								'index.php?option=com_csmcpforj&task=catalog.installAddon'
								. '&addon_key=' . rawurlencode($addonKey)
								. '&' . Session::getFormToken() . '=1'
							);
						}

						// Catalog-vs-installed version compare detects available updates.
						$rawInstalledVersion = (string) ($addon['installed_version'] ?? '');
						$rawCatalogVersion   = (string) ($addon['version'] ?? '');
						$updateAvailable     = $isInstalled
							&& $rawInstalledVersion !== ''
							&& $rawCatalogVersion !== ''
							&& version_compare($rawCatalogVersion, $rawInstalledVersion, '>');

						if ($isInstalled) {
							$toggleTask     = $isEnabled ? '0' : '1';
							$toggleLabelKey = $isEnabled ? 'COM_CSMCPFORJ_CATALOG_DISABLE_BUTTON' : 'COM_CSMCPFORJ_CATALOG_ENABLE_BUTTON';
							$toggleBtnClass = $isEnabled ? 'btn-outline-secondary' : 'btn-success';
							// btn-outline-* is unreadable in Atum dark mode for the destructive variant;
							// keep it because the SECONDARY-grey-outline still renders fine Ã¢â‚¬â€ the bug only
							// hits colored outlines (success/danger). Use solid btn-success on enable.
							$toggleUrl      = Route::_(
								'index.php?option=com_csmcpforj&task=catalog.toggleAddon'
								. '&extension_id=' . $extensionId
								. '&enabled=' . $toggleTask
								. '&' . Session::getFormToken() . '=1'
							);
						}
						?>
						<?php
						// Build the uninstall URL when an addon is installed.
						$uninstallUrl = '';
						if ($isInstalled && $extensionId > 0) {
							$uninstallUrl = Route::_(
								'index.php?option=com_csmcpforj&task=catalog.uninstallAddon'
								. '&extension_id=' . $extensionId
								. '&' . Session::getFormToken() . '=1'
							);
						}
						?>
						<div class="col catalog-card"
							data-name="<?php echo htmlspecialchars(strtolower((string) ($addon['name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
							data-tier="<?php echo $tier; ?>"
							data-installed="<?php echo $isInstalled ? 'yes' : 'no'; ?>"
							data-enabled="<?php echo $isInstalled && $isEnabled ? 'yes' : 'no'; ?>"
							data-update="<?php echo $updateAvailable ? 'yes' : 'no'; ?>">
							<div class="card h-100">
								<div class="card-body">
									<div class="d-flex justify-content-between align-items-start gap-2">
										<h5 class="card-title mb-1"><?php echo $name; ?></h5>
										<div class="d-flex flex-column align-items-end gap-1">
											<span class="badge <?php echo $tierClass; ?>"><?php echo htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8'); ?></span>
											<?php
											// Discovery hints from catalog_metadata.is_new / .is_experimental
											// (pass-through via api.catalog). Operator-flippable per-add-on via the
											// Package edit form on cs-release-manager -- no code deploy needed to
											// add or remove.
											//   is_new         -> light-green "NEW" pill (draws the eye)
											//   is_experimental -> yellow "EXPERIMENTAL" pill + tooltip pointing
											//                      to the advisory disclosure below the description.
											?>
											<?php if (!empty($addon['is_new'])) : ?>
												<span class="badge bg-success-subtle text-success border border-success" title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_BADGE_NEW_HINT'); ?>">
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_BADGE_NEW'); ?>
												</span>
											<?php endif; ?>
											<?php if (!empty($addon['is_experimental'])) : ?>
												<span class="badge bg-warning text-dark" title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_BADGE_EXPERIMENTAL_HINT'); ?>">
													<span class="icon-flag" aria-hidden="true"></span>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_BADGE_EXPERIMENTAL'); ?>
												</span>
											<?php endif; ?>
											<?php
											// Installed-state badge. Three visual states for a "you can see at
											// a glance whether you've already got this" cue (Bjørn + Ivar 2026-06-17):
											//   installed+enabled  → green ✓ "Installed & active"
											//   installed+disabled → grey   "Installed (disabled)"
											//   not installed      → no badge (catalog default)
											// Replaces the earlier ENABLED/DISABLED single-word badges which
											// were too terse to register as "you already have this."
											?>
											<?php if ($isInstalled && $isEnabled) : ?>
												<span class="badge bg-success" title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_STATE_INSTALLED_ACTIVE_HINT'); ?>">
													<span class="icon-checkmark" aria-hidden="true"></span>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_STATE_INSTALLED_ACTIVE'); ?>
												</span>
											<?php elseif ($isInstalled) : ?>
												<span class="badge bg-secondary" title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_STATE_INSTALLED_DISABLED_HINT'); ?>">
													<span class="icon-pause" aria-hidden="true"></span>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_STATE_INSTALLED_DISABLED'); ?>
												</span>
											<?php elseif ($isSuperseded) : ?>
												<span class="badge bg-info text-dark" title="<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_SUPERSEDED_HINT', $supersededByName); ?>">
													<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_SUPERSEDED_BY', $supersededByName); ?>
												</span>
											<?php endif; ?>
										</div>
									</div>
									<?php if ($version) : ?>
										<p class="text-body-secondary mb-1"><small>
											<?php echo Text::_('COM_CSMCPFORJ_CATALOG_CATALOG_VERSION'); ?> v<?php echo $version; ?>
											<?php if ($isInstalled && $installedVersion && $installedVersion !== $version) : ?>
												<span class="ms-2 text-warning">
													(<?php echo Text::_('COM_CSMCPFORJ_CATALOG_INSTALLED_VERSION'); ?> v<?php echo $installedVersion; ?>)
												</span>
											<?php endif; ?>
										</small></p>
									<?php endif; ?>
									<p class="card-text"><?php echo $desc; ?></p>

									<?php
									// Advisory disclosure. Renders when catalog_metadata.advisory
									// is populated for this add-on. Uses native <details> so it's
									// collapsed by default (no visual weight on cards that don't
									// need it) but the summary bar is always visible so prospective
									// users see the "read before install" cue. Bootstrap alert-*
									// classes drive the colour by severity. Emits raw HTML for the
									// message so a catalog entry can link to docs or format list
									// items when the advisory is long enough to need structure.
									$advisory = $addon['advisory'] ?? null;
									if (is_array($advisory) && (string) ($advisory['message'] ?? '') !== '') :
										$sev = (string) ($advisory['severity'] ?? 'info');
										if (!in_array($sev, ['info', 'warning', 'danger'], true)) { $sev = 'info'; }
										$sevClass = 'alert-' . $sev;
										$sevIcon  = $sev === 'danger'  ? 'icon-warning-circle'
												  : ($sev === 'warning' ? 'icon-warning'
												  : 'icon-info-circle');
										$advTitle = trim((string) ($advisory['title'] ?? '')) !== ''
											? htmlspecialchars((string) $advisory['title'], ENT_QUOTES, 'UTF-8')
											: Text::_('COM_CSMCPFORJ_CATALOG_ADVISORY_DEFAULT_TITLE');
									?>
										<details class="alert <?php echo $sevClass; ?> py-2 px-3 mb-2 small">
											<summary class="fw-bold" style="cursor: pointer;">
												<span class="<?php echo $sevIcon; ?>" aria-hidden="true"></span>
												<?php echo $advTitle; ?>
											</summary>
											<div class="mt-2"><?php echo (string) $advisory['message']; ?></div>
										</details>
									<?php endif; ?>

									<?php
									// Vendor-independence disclosure. Surface a small one-liner
									// for every add-on whose target_extension declares a vendor
									// that isn't Cybersalt — makes it explicit that we built the
									// MCP tools without any involvement from the target
									// extension's owner. Link points at a single web page on
									// cybersalt.com that explains the development policy in
									// more detail; URL is supplied by the catalog feed (top-level
									// `independence_notice_url`) with a sensible default in the
									// view layer.
									$vendorName = (string) (($addon['target_extension']['vendor_name'] ?? '') ?: '');
									if ($vendorName !== '' && strcasecmp($vendorName, 'Cybersalt') !== 0) :
										$vendorEscaped = htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8');
										$noticeUrl     = htmlspecialchars($this->independenceNoticeUrl, ENT_QUOTES, 'UTF-8');
									?>
										<p class="card-text mb-2">
											<small class="text-body-secondary">
												<span class="icon-info-circle" aria-hidden="true"></span>
												<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_INDEPENDENCE_NOTE', $vendorEscaped); ?>
												<?php if ($this->independenceNoticeUrl !== '') : ?>
													<a href="<?php echo $noticeUrl; ?>" target="_blank" rel="noopener" class="ms-1">
														<?php echo Text::_('COM_CSMCPFORJ_CATALOG_INDEPENDENCE_LEARN_MORE'); ?>
													</a>
												<?php endif; ?>
											</small>
										</p>
									<?php endif; ?>

									<div class="d-flex gap-1 flex-wrap">
										<?php if ($isInstalled) : ?>
											<a href="<?php echo $toggleUrl; ?>" class="btn btn-sm <?php echo $toggleBtnClass; ?>">
												<span class="icon-<?php echo $isEnabled ? 'remove' : 'checkmark'; ?>" aria-hidden="true"></span>
												<?php echo Text::_($toggleLabelKey); ?>
											</a>
											<?php if ($uninstallUrl !== '') : ?>
												<a href="<?php echo $uninstallUrl; ?>"
													class="btn btn-sm btn-danger"
													onclick="return confirm('<?php echo Text::_('COM_CSMCPFORJ_CATALOG_UNINSTALL_CONFIRM_JS', true); ?>');">
													<span class="icon-trash" aria-hidden="true"></span>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_UNINSTALL_BUTTON'); ?>
												</a>
											<?php endif; ?>
										<?php endif; ?>
										<?php
										// Supersede gate: if a larger version of this thing is already
										// installed (e.g. Akeeba Backup Pro present → Core wrapper
										// superseded), refuse all install/update CTAs. The user already
										// has the bigger version covered by a different MCP wrapper or
										// doesn't need a wrapper at all; pushing them to install the
										// smaller free wrapper would create confusing duplicate tool
										// surfaces. Toggle/uninstall on an ALREADY-installed superseded
										// row stays available so they can clean up if they want.
										$canInstall = !$isInstalled && !$isSuperseded && $installUrl !== ''
											&& (empty($addon['requires_pro_membership']) || !empty($addon['has_pro_membership']));
										$proLocked  = !$isInstalled && !$isSuperseded && !empty($addon['requires_pro_membership'])
											&& empty($addon['has_pro_membership']);
										$canUpdate  = $updateAvailable && !$isSuperseded && $installUrl !== ''
											&& (empty($addon['requires_pro_membership']) || !empty($addon['has_pro_membership']));
										?>
										<?php if ($canInstall) : ?>
											<?php $isPro = !empty($addon['requires_pro_membership']); ?>
											<a href="<?php echo $installUrl; ?>"
												class="btn btn-sm <?php echo $isPro ? 'btn-success' : 'btn-primary'; ?>"
												<?php if ($isPro) : ?>title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_PRO_ACTIVE_HINT'); ?>"<?php endif; ?>>
												<span class="icon-<?php echo $isPro ? 'checkmark' : 'download'; ?>" aria-hidden="true"></span>
												<?php if ($isPro) : ?>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_INSTALL_PRO_ACTIVE'); ?>
												<?php else : ?>
													<?php echo Text::_('COM_CSMCPFORJ_CATALOG_INSTALL'); ?>
												<?php endif; ?>
											</a>
										<?php elseif ($proLocked) : ?>
											<?php
											// `btn-outline-warning disabled` was unreadable in BOTH light
											// (pale yellow text on white) and dark (faded outline on charcoal)
											// Atum modes — Tim flagged it on the 2026-06-12 catalog walkthrough.
											// Fix: a solid bright-yellow pill with dark text + a bold lock icon
											// + opacity overrides so Bootstrap's `disabled` fade (default 0.65 via
											// --bs-btn-disabled-opacity) doesn't dim it back into invisibility.
											// Explicit hex `#ffd60a` is one notch brighter than Bootstrap's
											// `--bs-warning` (#ffc107) — pops on both Atum themes.
											?>
											<span class="btn btn-sm fw-bold"
												style="background-color: #ffd60a; color: #1f1f1f; border-color: #d4b106; cursor: not-allowed; pointer-events: none;"
												title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_PRO_MANUAL_HINT'); ?>">
												<span class="icon-lock" aria-hidden="true"></span>
												<?php echo Text::_('COM_CSMCPFORJ_CATALOG_PRO_MANUAL_INSTALL'); ?>
											</span>
										<?php elseif ($canUpdate) : ?>
											<?php $isPro = !empty($addon['requires_pro_membership']); ?>
											<a href="<?php echo $installUrl; ?>"
												class="btn btn-sm <?php echo $isPro ? 'btn-success' : 'btn-warning'; ?>"
												<?php if ($isPro) : ?>title="<?php echo Text::_('COM_CSMCPFORJ_CATALOG_PRO_ACTIVE_HINT'); ?>"<?php endif; ?>>
												<span class="icon-<?php echo $isPro ? 'checkmark' : 'upload'; ?>" aria-hidden="true"></span>
												<?php if ($isPro) : ?>
													<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_UPDATE_PRO_ACTIVE', 'v' . $version); ?>
												<?php else : ?>
													<?php echo Text::sprintf('COM_CSMCPFORJ_CATALOG_UPDATE_TO', 'v' . $version); ?>
												<?php endif; ?>
											</a>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<script>
				(function () {
					// Client-side filter mirroring Joomla's standard searchtools (.js-stools)
					// shell — search box + magnifying-glass + X clear button, with a
					// collapsible "Filter Options" panel of dropdowns below.
					//
					// Pure DOM toggle on .d-none. No form submission, no reload.
					// Reads data-* attributes each card carries and matches against
					// the dropdowns + search input.

					var searchEl      = document.getElementById('catalog-search');
					var searchTrigger = document.getElementById('catalog-search-trigger');
					var clearBtn      = document.getElementById('catalog-clear-filters');
					var toggleBtn     = document.getElementById('catalog-toggle-filters');
					var panelEl       = document.getElementById('catalog-filters-panel');
					var selects       = document.querySelectorAll('.catalog-filter-select');
					var cards         = document.querySelectorAll('.catalog-card');

					// Map of <select id> → card data-attribute it filters on.
					// Empty value ("") means the placeholder is selected → "don't
					// filter on this dimension." Matches Joomla's searchtools
					// convention where the first option of each filter is
					// "- Select Foo -" with value="".
					var SELECT_MAP = {
						'filter-tier':      'data-tier',
						'filter-installed': 'data-installed',
						'filter-enabled':   'data-enabled',
						'filter-update':    'data-update'
					};

					function apply() {
						var query = (searchEl.value || '').trim().toLowerCase();

						var filters = {};
						Object.keys(SELECT_MAP).forEach(function (id) {
							var el = document.getElementById(id);
							filters[id] = el ? el.value : '';
						});

						cards.forEach(function (card) {
							var name      = card.getAttribute('data-name') || '';
							var nameMatch = !query || name.indexOf(query) !== -1;

							var allMatch = nameMatch;
							if (allMatch) {
								for (var id in SELECT_MAP) {
									var wanted = filters[id];
									if (!wanted) continue;
									var actual = card.getAttribute(SELECT_MAP[id]) || '';
									if (actual !== wanted) {
										allMatch = false;
										break;
									}
								}
							}

							if (allMatch) {
								card.classList.remove('d-none');
							} else {
								card.classList.add('d-none');
							}
						});
					}

					function toggleFilterPanel(forceOpen) {
						if (!panelEl || !toggleBtn) return;
						// Use Bootstrap's d-none/d-flex class swap rather than inline
						// style.display, because Bootstrap's display utilities use
						// !important and would override an inline display:none.
						var isOpen   = !panelEl.classList.contains('d-none');
						var willOpen = (typeof forceOpen === 'boolean') ? forceOpen : !isOpen;
						if (willOpen) {
							panelEl.classList.remove('d-none');
						} else {
							panelEl.classList.add('d-none');
						}
						toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
					}

					searchEl.addEventListener('input', apply);
					searchEl.addEventListener('keydown', function (e) {
						if (e.key === 'Enter') { e.preventDefault(); apply(); }
					});

					if (searchTrigger) {
						searchTrigger.addEventListener('click', apply);
					}

					if (clearBtn) {
						clearBtn.addEventListener('click', function () {
							searchEl.value = '';
							selects.forEach(function (el) { el.value = ''; });
							apply();
						});
					}

					if (toggleBtn) {
						toggleBtn.addEventListener('click', function () { toggleFilterPanel(); });
					}

					selects.forEach(function (el) { el.addEventListener('change', apply); });

					apply();
				})();
				</script>
			<?php endif; ?>

		</div>
	</div>
</div>
