<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Cybersalt\Component\Csmcpforj\Administrator\View\Dashboard\HtmlView $this */

// Token URL deep-links to the CURRENT admin's profile (where the API
// Token tab lives). Built in HtmlView so it includes the user id —
// without it, Joomla drops the user on the user list, not their own
// profile.
$tokenUrl = htmlspecialchars($this->tokenProfileUrl, ENT_QUOTES, 'UTF-8');
$permsUrl = Route::_('index.php?option=com_config&view=component&component=com_csmcpforj');
$endpoint = htmlspecialchars($this->endpointUrl, ENT_QUOTES, 'UTF-8');
?>
<style>
	/* Highlight the placeholder / substituted token inside the copy-paste
	   prompt so the user can see exactly where their token will land. The
	   <mark> element is browser-styled but we override for higher contrast
	   in both Atum light and dark. */
	.csmcpforj-token-mark {
		background-color: #fff3cd;
		color: #664d03;
		padding: 0.05rem 0.35rem;
		border-radius: 0.25rem;
		font-weight: 600;
		border: 1px solid #ffe69c;
	}
	.csmcpforj-token-mark-empty {
		background-color: #e2e3e5;
		color: #41464b;
		border-color: #c4c6c9;
		font-style: italic;
	}
	/* Atum dark mode */
	[data-bs-theme="dark"] .csmcpforj-token-mark,
	.atum-dark .csmcpforj-token-mark {
		background-color: #664d03;
		color: #ffecb5;
		border-color: #997404;
	}
	[data-bs-theme="dark"] .csmcpforj-token-mark-empty,
	.atum-dark .csmcpforj-token-mark-empty {
		background-color: #343a40;
		color: #adb5bd;
		border-color: #495057;
	}
</style>
<div class="container-fluid">
	<div class="row">
		<div class="col-12 col-lg-8">

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_INTRO'); ?></p>
					<dl class="row mb-0">
						<dt class="col-sm-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_ENDPOINT'); ?></dt>
						<dd class="col-sm-9">
							<div class="input-group mb-1">
								<input type="text" id="csmcpforj-endpoint-url" class="form-control font-monospace"
									value="<?php echo $endpoint; ?>" readonly onclick="this.select();">
								<button type="button" class="btn btn-primary csmcpforj-copy-btn" data-target="csmcpforj-endpoint-url"
									title="<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON'); ?>">
									<span class="icon-copy" aria-hidden="true"></span>
									<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON'); ?>
								</button>
							</div>
							<small class="text-muted d-block">
								<span class="icon-info-circle" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_ENDPOINT_HINT'); ?>
							</small>
						</dd>
						<dt class="col-sm-3 mt-2"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN'); ?></dt>
						<dd class="col-sm-9 mt-2">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_VALUE'); ?>
							<a href="<?php echo $tokenUrl; ?>" target="_blank" rel="noopener" class="ms-2"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_LINK'); ?></a>
						</dd>
					</dl>

					<?php
					// Update-check action. Sits at the bottom of the Connection
					// card so operators see it in the same place they see the
					// endpoint URL — single place for "things about how this site
					// talks to cybersalt.com". Bypasses Joomla's #__updates cache
					// for every cs-mcp-for-j-related extension and force-polls
					// cs-release-manager so a release I just pushed shows up
					// without waiting for the default 24h cache window.
					$checkUpdatesUrl  = \Joomla\CMS\Router\Route::_('index.php?option=com_csmcpforj&task=dashboard.checkUpdatesNow');
					$systemFormToken  = \Joomla\CMS\Session\Session::getFormToken();
					?>
					<hr class="my-3">
					<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
						<div class="small text-muted">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_HINT'); ?>
						</div>
						<form action="<?php echo $checkUpdatesUrl; ?>" method="post" class="m-0">
							<input type="hidden" name="<?php echo $systemFormToken; ?>" value="1">
							<button type="submit" class="btn btn-sm btn-outline-primary">
								<span class="icon-refresh" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_BTN'); ?>
							</button>
						</form>
					</div>
				</div>
			</div>

			<script>
			(function () {
				// Tiny inline copy-to-clipboard handler — no external lib.
				// Mirrors the cs-release-manager admin pattern (.csrm-copy-btn).
				document.querySelectorAll('.csmcpforj-copy-btn').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var input = document.getElementById(btn.getAttribute('data-target'));
						if (!input) { return; }
						input.select();
						navigator.clipboard.writeText(input.value).then(function () {
							var original = btn.innerHTML;
							btn.innerHTML = <?php echo json_encode('<span class="icon-check" aria-hidden="true"></span> ' . Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
							btn.classList.remove('btn-primary');
							btn.classList.add('btn-success');
							setTimeout(function () {
								btn.innerHTML = original;
								btn.classList.remove('btn-success');
								btn.classList.add('btn-primary');
							}, 1500);
						});
					});
				});
			})();
			</script>

			<div class="card mb-3 border-info">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHODS_HEADING'); ?></h3>
					<p class="card-text mb-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHODS_INTRO'); ?></p>

					<ul class="nav nav-tabs" id="csmcpforj-method-tabs" role="tablist">
						<li class="nav-item" role="presentation">
							<button class="nav-link active" id="method-prompt-tab" data-bs-toggle="tab" data-bs-target="#method-prompt" type="button" role="tab" aria-controls="method-prompt" aria-selected="true">
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_TAB'); ?>
							</button>
						</li>
						<li class="nav-item" role="presentation">
							<button class="nav-link" id="method-connector-tab" data-bs-toggle="tab" data-bs-target="#method-connector" type="button" role="tab" aria-controls="method-connector" aria-selected="false">
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_TAB'); ?>
							</button>
						</li>
					</ul>

					<div class="tab-content pt-3" id="csmcpforj-method-tab-content">
						<div class="tab-pane fade show active" id="method-prompt" role="tabpanel" aria-labelledby="method-prompt-tab">
							<h4 class="h5"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_HEADING'); ?></h4>
							<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_INTRO'); ?></p>

							<div class="alert alert-success mb-3">
								<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_BONUS_LABEL'); ?></strong>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_BONUS_BODY'); ?>
							</div>

							<ol class="mb-3">
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_STEP1'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_STEP2'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_STEP3'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_STEP4'); ?></li>
							</ol>

							<div class="card mb-3 border-secondary">
								<div class="card-body py-3">
									<label for="csmcpforj-token-input" class="form-label fw-bold">
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_LABEL'); ?>
									</label>
									<div class="input-group">
										<input type="password" class="form-control" id="csmcpforj-token-input" placeholder="sha256:42:abc123def456..." autocomplete="off" data-csmcpforj-token-input>
										<button type="button" class="btn btn-outline-secondary" data-csmcpforj-token-toggle title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_TOGGLE_SHOW')); ?>">
											<span class="icon-eye" aria-hidden="true"></span>
										</button>
										<button type="button" class="btn btn-outline-danger d-none" data-csmcpforj-token-clear title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_CLEAR')); ?>">
											<span class="icon-trash" aria-hidden="true"></span>
										</button>
									</div>
									<small class="text-body-secondary d-block mt-2">
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_HINT'); ?>
										<span class="d-block mt-1" data-csmcpforj-token-status></span>
									</small>
								</div>
							</div>

							<pre class="p-2 mb-2" style="white-space: pre-wrap; max-height: 400px; overflow: auto;"><code id="csmcpforj-prompt"><?php echo htmlspecialchars($this->claudePrompt, ENT_QUOTES, 'UTF-8'); ?></code></pre>
							<button type="button" class="btn btn-primary btn-lg" data-csmcpforj-copy="csmcpforj-prompt" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_PROMPT_BUTTON')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED_WITH_TOKEN')); ?>">
								<span class="icon-copy" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_PROMPT_BUTTON'); ?>
							</button>

							<div class="alert alert-info mt-3 mb-0">
								<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_HINT'); ?></small>
							</div>
						</div>

						<div class="tab-pane fade" id="method-connector" role="tabpanel" aria-labelledby="method-connector-tab">
							<h4 class="h5"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_HEADING'); ?></h4>
							<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_INTRO'); ?></p>

							<ol class="mb-3">
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_STEP1'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_STEP2'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_STEP3'); ?></li>
								<li class="mb-1"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_STEP4'); ?></li>
							</ol>

							<pre class="p-2 mb-2" style="white-space: pre-wrap;"><code id="csmcpforj-config"><?php echo htmlspecialchars($this->mcpConfigJson, ENT_QUOTES, 'UTF-8'); ?></code></pre>
							<button type="button" class="btn btn-primary" data-csmcpforj-copy="csmcpforj-config" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED')); ?>">
								<span class="icon-copy" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON'); ?>
							</button>

							<p class="mt-3 mb-0"><small class="text-body-secondary">
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_XHEADER_NOTE'); ?>
								<code>"headers": { "X-Joomla-Token": "YOUR_JOOMLA_API_TOKEN" }</code>
							</small></p>
						</div>
					</div>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_INTRO'); ?></p>

					<div class="alert alert-info mb-3">
						<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_LABEL'); ?></strong>
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_BODY'); ?>
					</div>

					<table class="table table-sm">
						<thead>
							<tr>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_GROUP'); ?></th>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_OUTOFBOX'); ?></th>
								<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_COL_REASON'); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td><strong>Super Users</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_SUPER'); ?></td></tr>
							<tr><td><strong>Administrator</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_ADMIN'); ?></td></tr>
							<tr><td><strong>Manager</strong></td><td><span class="badge bg-success"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_YES'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_MANAGER'); ?></td></tr>
							<tr><td><strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_GROUP_OTHER'); ?></strong></td><td><span class="badge bg-warning text-dark"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_NEEDS_GRANT'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_OTHER'); ?></td></tr>
						</tbody>
					</table>

					<p class="mb-0">
						<a href="<?php echo $permsUrl; ?>" class="btn btn-secondary text-white">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_LINK_PERMISSIONS'); ?>
						</a>
					</p>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title">
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOLS_HEADING'); ?>
						<span class="badge bg-info"><?php echo (int) $this->toolCount; ?></span>
					</h3>

					<?php if (empty($this->toolsByDomain)) : ?>
						<p class="text-muted"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOLS_EMPTY'); ?></p>
					<?php else : ?>
						<?php foreach ($this->toolsByDomain as $domain => $tools) : ?>
							<h4 class="h6 mt-3 mb-2">
								<?php echo htmlspecialchars((string) $domain, ENT_QUOTES, 'UTF-8'); ?>
								<span class="badge bg-secondary"><?php echo count($tools); ?></span>
							</h4>
							<table class="table table-sm mb-3">
								<thead>
									<tr>
										<th style="width:30%;"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_NAME'); ?></th>
										<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_DESCRIPTION'); ?></th>
										<th style="width:90px;"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOOL_PERMISSION'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($tools as $tool) : ?>
										<tr>
											<td><code><?php echo htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8'); ?></code></td>
											<td><?php echo htmlspecialchars($tool['description'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td>
												<?php if (($tool['permission'] ?? 'use') === 'write') : ?>
													<span class="badge bg-warning text-dark">write</span>
												<?php else : ?>
													<span class="badge bg-success">read</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="col-12 col-lg-4">
			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_HEADING'); ?></h3>
					<ol class="ps-3">
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP1_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP1_BODY'); ?></small>
						</li>
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP2_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP2_BODY'); ?></small>
						</li>
						<li class="mb-2">
							<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP3_TITLE'); ?></strong><br>
							<small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_QUICKSTART_STEP3_BODY'); ?></small>
						</li>
					</ol>
					<a href="<?php echo $tokenUrl; ?>" target="_blank" rel="noopener" class="btn btn-primary text-white d-block mb-2">
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_LINK_GENERATE_TOKEN'); ?>
					</a>
				</div>
			</div>

			<?php
			$proSubmitUrl   = \Joomla\CMS\Router\Route::_('index.php?option=com_csmcpforj&task=dashboard.activatePro');
			$proDeactivate  = \Joomla\CMS\Router\Route::_('index.php?option=com_csmcpforj&task=dashboard.deactivatePro');
			$proRefresh     = \Joomla\CMS\Router\Route::_('index.php?option=com_csmcpforj&task=dashboard.refreshMembership');
			$proFormToken   = \Joomla\CMS\Session\Session::getFormToken();
			?>
			<?php
			// Pro Activation card — 4-state UI driven by ProActivationHelper +
			// cs-release-manager's api.verifyaccess endpoint.
			//
			// Layout principles:
			//   - The card chrome stays neutral; only the ACTIVE state gets the
			//     green border so success reads clearly. Non-active states use
			//     no border emphasis — they're not failures, they're
			//     "registered, but here's the next step".
			//   - The state indicator lives INLINE above the email input, not
			//     across the top of the card. So a "Lapsed" state reads as
			//     "this email registered successfully; it's currently in the
			//     renewable tier, click Renew" instead of "your activation
			//     attempt failed".
			//   - The form is ALWAYS present except when state=active (no
			//     point re-trying) or state=blacklisted (different email won't
			//     help on a blocked domain).
			$cardBorder = $this->proStatus === 'active' ? 'border-success' : '';
			$emailEscaped = htmlspecialchars($this->proEmail, ENT_QUOTES, 'UTF-8');
			?>
			<div class="card mb-3 <?php echo $cardBorder; ?>">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_HEADING'); ?></h3>

					<?php
					// Two distinct layouts driven by whether an email has been
					// registered to this install:
					//
					//   1. CLEAN (no email yet) — editable input + Activate Pro button
					//   2. REGISTERED (any email + state) — readonly input showing
					//      the registered email, NO Activate Pro button, and a
					//      state-specific primary action button instead:
					//        active       → Deactivate locally
					//        lapsed       → Renew Membership (jumps to URL)
					//        not_a_member → Register for Membership (jumps to URL)
					//        blacklisted  → no action button (Deactivate link only)
					//        denied       → no action button (Deactivate link only)
					//
					// Plus a small "Deactivate locally" link at the bottom of
					// every REGISTERED state EXCEPT active (active has the
					// Deactivate button as its primary action). The small link
					// is the escape hatch for "I want to try a different email".
					$hasEmail   = $this->proEmail !== '';
					$badgeInfo  = match ($this->proStatus) {
						'active'       => ['bg-success',           'COM_CSMCPFORJ_DASHBOARD_PRO_BADGE_ACTIVE',  'COM_CSMCPFORJ_DASHBOARD_PRO_ACTIVE_BODY'],
						'lapsed'       => ['bg-warning text-dark', 'COM_CSMCPFORJ_DASHBOARD_PRO_BADGE_EXPIRED', 'COM_CSMCPFORJ_DASHBOARD_PRO_EXPIRED_BODY'],
						'not_a_member' => ['bg-secondary',         'COM_CSMCPFORJ_DASHBOARD_PRO_BADGE_NONE',    'COM_CSMCPFORJ_DASHBOARD_PRO_NONE_BODY'],
						'blacklisted'  => ['bg-danger',            'COM_CSMCPFORJ_DASHBOARD_PRO_BADGE_BLOCKED', 'COM_CSMCPFORJ_DASHBOARD_PRO_BLACKLISTED_BODY'],
						'denied'       => ['bg-warning text-dark', 'COM_CSMCPFORJ_DASHBOARD_PRO_BADGE_DENIED',  'COM_CSMCPFORJ_DASHBOARD_PRO_DENIED_BODY'],
						default        => [null, null, null],
					};
					?>

					<p class="card-text small"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_INTRO'); ?></p>

					<?php if (!$hasEmail) : ?>
						<?php // === CLEAN SLATE: editable input + Activate Pro button === ?>
						<form action="<?php echo $proSubmitUrl; ?>" method="post" class="row g-2 align-items-end">
							<input type="hidden" name="<?php echo $proFormToken; ?>" value="1">
							<div class="col-12">
								<label for="pro-email" class="form-label mb-1">
									<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_EMAIL_LABEL'); ?>
								</label>
								<input type="email" id="pro-email" name="email" class="form-control"
									placeholder="<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_EMAIL_PLACEHOLDER'); ?>"
									value=""
									autocomplete="email"
									required>
							</div>
							<div class="col-12">
								<button type="submit" class="btn btn-primary w-100">
									<span class="icon-key" aria-hidden="true"></span>
									<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_ACTIVATE_BUTTON'); ?>
								</button>
							</div>
						</form>

					<?php else : ?>
						<?php // === REGISTERED: readonly email + state-specific primary action === ?>
						<div class="row g-2 align-items-end">
							<div class="col-12">
								<?php if ($badgeInfo[0] !== null) : ?>
									<div class="mb-2">
										<span class="badge <?php echo $badgeInfo[0]; ?>"><?php echo Text::_($badgeInfo[1]); ?></span>
										<?php if ($badgeInfo[2]) : ?>
											<small class="text-body-secondary ms-1"><?php echo Text::_($badgeInfo[2]); ?></small>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<label for="pro-email" class="form-label mb-1">
									<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_EMAIL_LABEL'); ?>
								</label>
								<input type="email" id="pro-email" class="form-control"
									value="<?php echo $emailEscaped; ?>"
									readonly>
							</div>

							<div class="col-12">
								<?php if ($this->proStatus === 'active') : ?>
									<form action="<?php echo $proDeactivate; ?>" method="post">
										<input type="hidden" name="<?php echo $proFormToken; ?>" value="1">
										<button type="submit" class="btn btn-outline-secondary w-100"
											onclick="return confirm('<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_DEACTIVATE_CONFIRM', true); ?>');">
											<span class="icon-remove" aria-hidden="true"></span>
											<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_DEACTIVATE'); ?>
										</button>
									</form>
								<?php elseif ($this->proStatus === 'lapsed' && $this->proRenewalUrl !== '') : ?>
									<a href="<?php echo htmlspecialchars($this->proRenewalUrl, ENT_QUOTES, 'UTF-8'); ?>"
										target="_blank" rel="noopener"
										class="btn btn-warning text-dark fw-bold w-100">
										<span class="icon-refresh" aria-hidden="true"></span>
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_RENEW_BTN'); ?>
									</a>
								<?php elseif ($this->proStatus === 'not_a_member' && $this->proSignupUrl !== '') : ?>
									<a href="<?php echo htmlspecialchars($this->proSignupUrl, ENT_QUOTES, 'UTF-8'); ?>"
										target="_blank" rel="noopener"
										class="btn btn-primary fw-bold w-100">
										<span class="icon-cart" aria-hidden="true"></span>
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_REGISTER_BTN'); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>

						<?php
						// Refresh Membership Status — re-runs verifyaccess against
						// cs-release-manager immediately, bypassing the recheck_seconds
						// TTL. Visible in EVERY non-clean state (active too) so a user
						// who just renewed on cybersalt.com can flip themselves out of
						// 'lapsed' / 'denied' without waiting up to a day for the
						// scheduled recheck. The button also covers the "I'm Active
						// but my package_title looks stale" case — cheap to click.
						?>
						<form action="<?php echo $proRefresh; ?>" method="post" class="mt-2">
							<input type="hidden" name="<?php echo $proFormToken; ?>" value="1">
							<button type="submit" class="btn btn-outline-info w-100">
								<span class="icon-loop" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_REFRESH_BTN'); ?>
							</button>
							<small class="text-muted d-block mt-1 text-center">
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_REFRESH_HINT'); ?>
							</small>
						</form>

						<?php
						// Second button below the primary state-specific action:
						// "Disconnect this email" — full-width neutral button so
						// it visually parallels the primary CTA (Renew / Register).
						// Active state has its own big Deactivate button as the
						// primary action; no need to duplicate here.
						if ($this->proStatus !== 'active') : ?>
							<form action="<?php echo $proDeactivate; ?>" method="post" class="mt-2">
								<input type="hidden" name="<?php echo $proFormToken; ?>" value="1">
								<button type="submit" class="btn btn-outline-secondary w-100"
									onclick="return confirm('<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_DEACTIVATE_CONFIRM', true); ?>');">
									<span class="icon-remove" aria-hidden="true"></span>
									<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_DISCONNECT_BTN'); ?>
								</button>
							</form>
						<?php endif; ?>
					<?php endif; ?>

					<?php
					// Installation id at the bottom of the card for non-active
					// states — purely diagnostic, helps with support tickets.
					if ($this->proInstallationId !== '' && $this->proStatus !== 'active') : ?>
						<p class="card-text mt-3 mb-0">
							<small class="text-body-secondary">
								<?php echo Text::sprintf('COM_CSMCPFORJ_DASHBOARD_PRO_INSTALLATION_ID', htmlspecialchars($this->proInstallationId, ENT_QUOTES, 'UTF-8')); ?>
							</small>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-body">
					<h4 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_HEADING'); ?></h4>
					<dl class="mb-0">
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_401_TITLE'); ?></dt>
						<dd><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_401_BODY'); ?></small></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_404_TITLE'); ?></dt>
						<dd><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_404_BODY'); ?></small></dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_FORBIDDEN_TITLE'); ?></dt>
						<dd class="mb-0"><small><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TROUBLESHOOT_FORBIDDEN_BODY'); ?></small></dd>
					</dl>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	// Token field — persistence + show/hide + clear-button wiring.
	// The token is held in this browser's localStorage only. It never leaves
	// the browser, the dashboard never sends it back to the server. Per-origin
	// storage means a separate token can be remembered for every Joomla site
	// you load this dashboard on.
	var STORAGE_KEY  = 'csmcpforj.api_token';
	var STATUS_SAVED = <?php echo json_encode(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_STATUS_SAVED')); ?>;
	var STATUS_EMPTY = <?php echo json_encode(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_STATUS_EMPTY')); ?>;

	var tokenToggle = document.querySelector('[data-csmcpforj-token-toggle]');
	var tokenInput  = document.querySelector('[data-csmcpforj-token-input]');
	var tokenClear  = document.querySelector('[data-csmcpforj-token-clear]');
	var tokenStatus = document.querySelector('[data-csmcpforj-token-status]');

	// Snapshot the prompt template BEFORE any substitution so we can re-render
	// it whenever the token state changes (paste, clear, refresh-with-saved).
	var promptEl       = document.getElementById('csmcpforj-prompt');
	var promptTemplate = promptEl ? promptEl.textContent : '';
	var TOKEN_PLACEHOLDER_RE = /<PASTE YOUR JOOMLA API TOKEN HERE>/g;
	var PLACEHOLDER_TEXT     = '<PASTE YOUR JOOMLA API TOKEN HERE>';

	function escapeHtml(s) {
		return s.replace(/[&<>"']/g, function(c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// Render the prompt. When a token is set, wrap the substituted value in
	// <mark> so the user can see at a glance where their token landed. The
	// copy handler reads textContent which strips the <mark> tags, so the
	// clipboard gets clean text. When no token is set, the placeholder gets
	// the same highlight so the user can see WHICH part of the prompt will
	// be replaced once they paste a token in.
	function renderPrompt() {
		if (!promptEl) return;
		var token = tokenInput ? (tokenInput.value || '').trim() : '';
		var parts = promptTemplate.split(PLACEHOLDER_TEXT);
		var marker = token !== ''
			? '<mark class="csmcpforj-token-mark">' + escapeHtml(token) + '</mark>'
			: '<mark class="csmcpforj-token-mark csmcpforj-token-mark-empty">' + escapeHtml(PLACEHOLDER_TEXT) + '</mark>';
		promptEl.innerHTML = parts.map(escapeHtml).join(marker);
	}

	function setStatus(text) {
		if (tokenStatus) tokenStatus.textContent = text || '';
	}
	function showClearButton(show) {
		if (tokenClear) tokenClear.classList.toggle('d-none', !show);
	}

	if (tokenInput) {
		// Restore on page load
		try {
			var saved = window.localStorage.getItem(STORAGE_KEY);
			if (saved) {
				tokenInput.value = saved;
				setStatus(STATUS_SAVED);
				showClearButton(true);
			} else {
				setStatus(STATUS_EMPTY);
			}
		} catch (e) {
			// localStorage may be unavailable (private mode, etc.) — silent fallback
		}
		renderPrompt();

		// Save on every change/blur — both events because some browsers don't fire
		// change reliably after paste-then-tab. The handler is cheap.
		var saveToken = function() {
			try {
				var v = (tokenInput.value || '').trim();
				if (v === '') {
					window.localStorage.removeItem(STORAGE_KEY);
					setStatus(STATUS_EMPTY);
					showClearButton(false);
				} else {
					window.localStorage.setItem(STORAGE_KEY, v);
					setStatus(STATUS_SAVED);
					showClearButton(true);
				}
			} catch (e) {}
			renderPrompt();
		};
		tokenInput.addEventListener('change', saveToken);
		tokenInput.addEventListener('blur', saveToken);
		tokenInput.addEventListener('input', saveToken);
	}

	if (tokenToggle && tokenInput) {
		tokenToggle.addEventListener('click', function() {
			tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
		});
	}

	if (tokenClear && tokenInput) {
		tokenClear.addEventListener('click', function() {
			tokenInput.value = '';
			try { window.localStorage.removeItem(STORAGE_KEY); } catch (e) {}
			setStatus(STATUS_EMPTY);
			showClearButton(false);
			renderPrompt();
			tokenInput.focus();
		});
	}

	// Copy buttons. If data-csmcpforj-token-substitute is set on the button
	// AND the token input has a non-empty value, substitute the placeholder
	// in the copied text — token never leaves the browser.
	document.querySelectorAll('[data-csmcpforj-copy]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var targetId = btn.getAttribute('data-csmcpforj-copy');
			var el = document.getElementById(targetId);
			if (!el) return;
			var text = el.textContent || el.innerText;

			var substituted = false;
			if (btn.getAttribute('data-csmcpforj-token-substitute') === '1' && tokenInput) {
				var token = (tokenInput.value || '').trim();
				if (token !== '') {
					text = text.replace(/<PASTE YOUR JOOMLA API TOKEN HERE>/g, token);
					substituted = true;
				}
			}

			var copiedLabel = substituted && btn.getAttribute('data-copied-substituted-label')
				? btn.getAttribute('data-copied-substituted-label')
				: btn.getAttribute('data-copied-label');
			var defaultLabel = btn.getAttribute('data-default-label');

			navigator.clipboard.writeText(text).then(function() {
				var iconHtml = '<span class="icon-checkmark" aria-hidden="true"></span> ';
				btn.innerHTML = iconHtml + copiedLabel;
				setTimeout(function() {
					btn.innerHTML = '<span class="icon-copy" aria-hidden="true"></span> ' + defaultLabel;
				}, 2500);
			}).catch(function() {
				// Fallback: select text so user can Ctrl+C manually. Note
				// fallback can't substitute the token because we'd need to
				// mutate the DOM and can't easily undo it.
				var range = document.createRange();
				range.selectNode(el);
				window.getSelection().removeAllRanges();
				window.getSelection().addRange(range);
			});
		});
	});
});
</script>
