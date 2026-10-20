<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\FieldStyleHelper;
use Joomla\CMS\HTML\HTMLHelper;
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
<?php echo FieldStyleHelper::css(); ?>
<style>
	/* Highlight the placeholder / substituted token inside the copy-paste
	   prompt so the user can see exactly where their token will land. The
	   <mark> element is browser-styled but we override for higher contrast
	   in both Atum light and dark.

	   The substituted token itself is BLURRED on screen (filter: blur) so
	   screencasts and screen shares don't leak the operator's actual API
	   key (Tim 2026-08-15 — needed for training-video capture). Copy still
	   works because clipboard payload reads the DOM textContent, not the
	   rendered CSS. Hover reveals the token for the operator's own peek —
	   also useful when the placeholder is showing (nothing to hide there
	   but the transition looks intentional). The blur is deliberately
	   heavy enough to defeat frame-grab OCR at normal reading distances. */
	.csmcpforj-token-mark {
		background-color: #fff3cd;
		color: #664d03;
		padding: 0.05rem 0.35rem;
		border-radius: 0.25rem;
		font-weight: 600;
		border: 1px solid #ffe69c;
		filter: blur(5px);
		transition: filter 0.15s ease;
	}
	/* Never blur the placeholder-mode variant — placeholder text is public
	   ("<PASTE YOUR JOOMLA API TOKEN HERE>") and users need to see it. */
	.csmcpforj-token-mark-empty,
	.csmcpforj-token-mark.csmcpforj-token-mark-empty {
		filter: none;
	}

	/* Pro activation email is blurred by default too — same rationale as
	   the token pill: screencasting-safe (Tim 2026-08-20). Only the
	   read-only variant (post-activation, showing the registered email)
	   gets blurred; the editable variant on the activation form has no
	   value yet so nothing to hide. */
	#pro-email[readonly] {
		filter: blur(5px);
		transition: filter 0.15s ease;
	}

	/* Reveal state applied by the JS countdown — one class name for both
	   token pill AND email input so the JS can toggle a single className
	   regardless of which element type. When present, blur is off. */
	.csmcpforj-secret-revealed,
	.csmcpforj-secret-revealed:hover,
	.csmcpforj-secret-revealed:focus {
		filter: none !important;
	}

	/* Countdown pill — appears CENTERED on the blurred secret while the
	   user is holding hover for the configured delay. Overlays the blurred
	   content (which is illegible anyway) so the "waiting" state is
	   unambiguous regardless of the element's size or where the mouse is
	   pointing within it (Tim 2026-08-21 — the earlier right-of-element
	   positioning wasn't visible on the readonly email input because the
	   wrapper was column-sized, and Tim wanted more explicit copy than a
	   bare number). Fades in/out on show/hide via opacity transition. */
	.csmcpforj-secret-hover-scope {
		position: relative;
		display: inline-block;
	}
	.csmcpforj-secret-countdown {
		position: absolute;
		left: 50%;
		top: 50%;
		transform: translate(-50%, -50%);
		background: var(--bs-body-color);
		color: var(--bs-body-bg);
		font-size: 0.875rem;
		font-weight: 700;
		padding: 0.35rem 0.75rem;
		border-radius: 0.75rem;
		pointer-events: none;
		opacity: 0;
		transition: opacity 0.1s ease;
		z-index: 5;
		white-space: nowrap;
		box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
	}
	.csmcpforj-secret-countdown.is-visible {
		opacity: 1;
	}
	.csmcpforj-token-mark-empty {
		background-color: #e2e3e5;
		color: #41464b;
		border-color: #c4c6c9;
		font-style: italic;
	}
	/* The three ways to connect: one collapsible section each. Bootstrap
	   variables only, so Atum light and dark both render correctly. */
	details.csmcpforj-way {
		border: 1px solid var(--bs-border-color);
		border-radius: 0.5rem;
		background: var(--bs-body-bg);
	}
	details.csmcpforj-way > summary {
		padding: 0.75rem 1rem;
		font-weight: 600;
		font-size: 1.05rem;
		cursor: pointer;
	}
	details.csmcpforj-way[open] > summary {
		border-bottom: 1px solid var(--bs-border-color);
	}
	details.csmcpforj-way > .csmcpforj-way-body {
		padding: 1rem;
	}
	/* Step numbers: green and a little larger, to read as encouraging
	   progress (Tim 2026-10-15). The step where you paste something keeps
	   the brand-orange badge from the findable-field pattern, so the one
	   thing you type into still stands out. */
	details.csmcpforj-way .csmcpforj-steps > li {
		padding: .5rem 0;
	}
	details.csmcpforj-way .csmcpforj-steps > li::before {
		background: #198754;
		color: #fff;
		width: 1.9rem;
		height: 1.9rem;
		line-height: 1.9rem;
		font-size: .95rem;
	}
	details.csmcpforj-way .csmcpforj-steps > li.csmcpforj-step-do::before {
		background: #fa6400;
	}
	/* Room between the per-app rows. */
	details.csmcpforj-way .csmcpforj-step-body ul > li {
		margin-bottom: .65rem;
	}
	details.csmcpforj-way .csmcpforj-step-body ul > li:last-child {
		margin-bottom: 0;
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

			<?php
			// ONE card, three ways to connect, each a collapsible section with
			// numbered steps (Tim 2026-10-15: "this needs to be super clear ...
			// dropdown arrows that separate the explanation of how to hook it
			// up in the different methods"). All three start collapsed (Tim, same
			// day: "better if all are collapsed at the beginning").
			//
			// The token field appears in ways 2 and 3. Both copies are kept in
			// sync by the script at the bottom, so pasting once fills both.
			$tokenStep = static function (string $suffix) use ($tokenUrl): void { ?>
				<li>
					<div class="csmcpforj-step-body">
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_GET_TOKEN_STEP'); ?>
						<div class="mt-2">
							<a href="<?php echo $tokenUrl; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary text-white">
								<span class="icon-key" aria-hidden="true"></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_GET_TOKEN_BTN'); ?>
							</a>
						</div>
					</div>
				</li>
				<li class="csmcpforj-step-do">
					<div class="csmcpforj-step-body">
						<label for="csmcpforj-token-input-<?php echo $suffix; ?>" class="csmcpforj-field-label">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_LABEL'); ?>
						</label>
						<div class="input-group">
							<input type="password" class="form-control" id="csmcpforj-token-input-<?php echo $suffix; ?>" placeholder="sha256:42:abc123def456..." autocomplete="off" data-csmcpforj-token-input>
							<button type="button" class="btn btn-outline-secondary" data-csmcpforj-token-toggle title="<?php echo htmlspecialchars(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_TOGGLE_SHOW'), ENT_QUOTES, 'UTF-8'); ?>">
								<span class="icon-eye" aria-hidden="true"></span>
							</button>
							<button type="button" class="btn btn-danger text-white d-none" data-csmcpforj-token-clear title="<?php echo htmlspecialchars(Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_CLEAR'), ENT_QUOTES, 'UTF-8'); ?>">
								<span class="icon-trash" aria-hidden="true"></span>
							</button>
						</div>
						<small class="text-body-secondary csmcpforj-field-hint">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_TOKEN_INPUT_HINT'); ?>
							<span class="d-block mt-1" data-csmcpforj-token-status></span>
						</small>
					</div>
				</li>
			<?php }; ?>

			<div class="card mb-3 border-info" id="csmcpforj-connect">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECT_HEADING'); ?></h3>
					<p class="card-text mb-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECT_INTRO'); ?></p>

					<?php // ---- Way 1: log in (OAuth) ---- ?>
					<details class="csmcpforj-way mb-2" id="csmcpforj-oauth">
						<summary><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY1_SUMMARY'); ?></summary>
						<div class="csmcpforj-way-body">
							<?php if (!$this->oauthEnabled) : ?>
								<p class="card-text"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_OFF'); ?></p>
								<a class="btn btn-sm btn-primary text-white" href="<?php echo $this->escape($this->optionsUrl); ?>"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_ENABLE'); ?></a>
							<?php else : ?>
								<p class="card-text mb-2"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_ON'); ?></p>
								<ol class="csmcpforj-steps">
									<li>
										<div class="csmcpforj-step-body">
											<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_STEP1_COPY'); ?>
											<div class="input-group mt-2">
												<input type="text" id="csmcpforj-endpoint-url-oauth" class="form-control font-monospace" value="<?php echo $endpoint; ?>" readonly onclick="this.select();">
												<button type="button" class="btn btn-primary text-white csmcpforj-copy-btn" data-target="csmcpforj-endpoint-url-oauth">
													<span class="icon-copy" aria-hidden="true"></span>
													<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_URL_BUTTON'); ?>
												</button>
											</div>
										</div>
									</li>
									<li>
										<div class="csmcpforj-step-body">
											<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_STEP2_ADD'); ?>
											<ul class="mt-2 mb-0">
												<?php foreach (['CLAUDE_AI', 'CHATGPT', 'CLAUDE_CODE', 'VSCODE', 'CURSOR', 'GEMINI', 'CODEX', 'CLINE'] as $oauthApp) : ?>
													<li><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_OAUTH_CLIENT_' . $oauthApp); ?></li>
												<?php endforeach; ?>
											</ul>
										</div>
									</li>
									<li><div class="csmcpforj-step-body"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_STEP2'); ?></div></li>
									<li><div class="csmcpforj-step-body"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_STEP3'); ?></div></li>
								</ol>
								<p class="card-text small mb-3" id="csmcpforj-oauth-selftest" data-url="<?php echo $this->escape($this->oauthMetadataUrl); ?>">
									<span class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_SELFTEST_RUNNING'); ?></span>
								</p>
								<?php if ($this->oauthProblems24h > 0) : ?>
									<p class="card-text small mb-3 text-warning-emphasis">
										<?php echo Text::sprintf('COM_CSMCPFORJ_DASHBOARD_OAUTH_PROBLEMS', $this->oauthProblems24h, $this->escape($this->oauthProblemsUrl)); ?>
									</p>
								<?php endif; ?>

								<h4 class="h6"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_APPS_HEADING'); ?></h4>
								<?php if ($this->oauthAuthorisations === []) : ?>
									<p class="card-text small text-body-secondary mb-0"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_APPS_NONE'); ?></p>
								<?php else : ?>
									<div class="table-responsive">
										<table class="table table-sm align-middle mb-0">
											<thead>
												<tr>
													<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_COL_APP'); ?></th>
													<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_COL_USER'); ?></th>
													<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_COL_CONNECTED'); ?></th>
													<th><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_COL_LAST_USED'); ?></th>
													<th></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ($this->oauthAuthorisations as $grant) : ?>
													<tr>
														<td><?php echo $this->escape((string) $grant->client_name); ?></td>
														<td><?php echo $this->escape((string) ($grant->user_name ?? '')); ?> <small class="text-body-secondary">(<?php echo $this->escape((string) ($grant->username ?? '#' . (int) $grant->user_id)); ?>)</small></td>
														<td><small><?php echo $grant->first_seen ? HTMLHelper::_('date', $grant->first_seen, Text::_('DATE_FORMAT_LC5')) : '&mdash;'; ?></small></td>
														<td><small><?php echo $grant->last_used ? HTMLHelper::_('date', $grant->last_used, Text::_('DATE_FORMAT_LC5')) : '&mdash;'; ?></small></td>
														<td class="text-end">
															<form method="post" action="<?php echo Route::_('index.php?option=com_csmcpforj&task=dashboard.revokeOAuth'); ?>" class="d-inline">
																<input type="hidden" name="client_id" value="<?php echo $this->escape((string) $grant->client_id); ?>">
																<input type="hidden" name="user_id" value="<?php echo (int) $grant->user_id; ?>">
																<?php echo HTMLHelper::_('form.token'); ?>
																<button type="submit" class="btn btn-sm btn-danger text-white"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_REVOKE'); ?></button>
															</form>
														</td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</details>

					<?php // ---- Way 2: setup prompt ---- ?>
					<details class="csmcpforj-way mb-2">
						<summary><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY2_SUMMARY'); ?></summary>
						<div class="csmcpforj-way-body">
							<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY2_INTRO'); ?></p>
							<ol class="csmcpforj-steps">
								<?php $tokenStep('prompt'); ?>
								<li>
									<div class="csmcpforj-step-body">
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY2_STEP_COPY'); ?>
										<pre class="p-2 mt-2 mb-2" style="white-space: pre-wrap; max-height: 300px; overflow: auto;"><code id="csmcpforj-prompt" data-csmcpforj-token-template="&lt;PASTE YOUR JOOMLA API TOKEN HERE&gt;"><?php echo htmlspecialchars($this->clientPrompt, ENT_QUOTES, 'UTF-8'); ?></code></pre>
										<button type="button" class="btn btn-primary text-white" data-csmcpforj-copy="csmcpforj-prompt" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_PROMPT_BUTTON')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED_WITH_TOKEN')); ?>">
											<span class="icon-copy" aria-hidden="true"></span>
											<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_PROMPT_BUTTON'); ?>
										</button>
									</div>
								</li>
								<li><div class="csmcpforj-step-body"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY2_STEP_PASTE'); ?></div></li>
							</ol>
							<p class="mb-0"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_PROMPT_HINT'); ?></small></p>
						</div>
					</details>

					<?php // ---- Way 3: config file / command line ---- ?>
					<details class="csmcpforj-way">
						<summary><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY3_SUMMARY'); ?></summary>
						<div class="csmcpforj-way-body">
							<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY3_INTRO'); ?></p>
							<ol class="csmcpforj-steps">
								<?php $tokenStep('config'); ?>
								<li>
									<div class="csmcpforj-step-body">
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY3_STEP_COPY'); ?>
										<pre class="p-2 mt-2 mb-2" style="white-space: pre-wrap;"><code id="csmcpforj-config" data-csmcpforj-token-template="YOUR_JOOMLA_API_TOKEN"><?php echo htmlspecialchars($this->mcpConfigJson, ENT_QUOTES, 'UTF-8'); ?></code></pre>
										<button type="button" class="btn btn-primary text-white" data-csmcpforj-copy="csmcpforj-config" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED_WITH_TOKEN')); ?>">
											<span class="icon-copy" aria-hidden="true"></span>
											<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_BUTTON'); ?>
										</button>
									</div>
								</li>
								<li><div class="csmcpforj-step-body"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_STEP4'); ?></div></li>
							</ol>
							<p class="mb-1"><small class="text-body-secondary">
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_METHOD_CONNECTOR_XHEADER_NOTE'); ?>
								<code>"headers": { "X-Joomla-Token": "YOUR_JOOMLA_API_TOKEN" }</code>
							</small></p>
							<p class="mb-0"><a href="<?php echo Route::_('index.php?option=com_csmcpforj&view=setupguide'); ?>"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_WAY3_GUIDE_LINK'); ?></a></p>
						</div>
					</details>
				</div>
			</div>

			<script>
			document.addEventListener('DOMContentLoaded', function () {
				// Tiny inline copy-to-clipboard handler for read-only URL fields.
				// Waits for the whole page: the side column's Copy button comes later.
				document.querySelectorAll('.csmcpforj-copy-btn').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var input = document.getElementById(btn.getAttribute('data-target'));
						if (!input) { return; }
						input.select();
						navigator.clipboard.writeText(input.value).then(function () {
							var original = btn.innerHTML;
							btn.innerHTML = <?php echo json_encode('<span class="icon-checkmark" aria-hidden="true"></span> ' . Text::_('COM_CSMCPFORJ_DASHBOARD_COPIED'), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
							setTimeout(function () { btn.innerHTML = original; }, 1500);
						});
					});
				});

				// "Ready / Not ready": can AI apps find the login details at
				// /.well-known/? Same-origin, read-only.
				var el = document.getElementById('csmcpforj-oauth-selftest');
				if (!el || !window.fetch) { return; }
				var ok  = <?php echo json_encode(Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_SELFTEST_OK')); ?>;
				var bad = <?php echo json_encode(Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_SELFTEST_FAIL')); ?>;
				fetch(el.getAttribute('data-url'), {credentials: 'omit', cache: 'no-store'})
					.then(function (r) { return r.ok ? r.json() : Promise.reject(); })
					.then(function (j) {
						if (!j || !j.authorization_endpoint) { throw new Error(); }
						el.innerHTML = '<span class="text-success">&#10003; ' + ok + '</span>';
					})
					.catch(function () { el.innerHTML = '<span class="text-danger">&#10007; ' + bad + '</span>'; });
			});
			</script>

			<div class="card mb-3">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_HEADING'); ?></h3>
					<p><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_INTRO'); ?></p>

					<div class="alert alert-info mb-3">
						<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_LABEL'); ?></strong>
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_KEY_FACT_BODY'); ?>
					</div>

					<div class="csmcpforj-notice mb-3">
						<strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_API_LOGIN_LABEL'); ?></strong>
						<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_API_LOGIN_BODY'); ?>
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
							<tr><td><strong>Administrator</strong></td><td><span class="badge bg-warning text-dark"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_NEEDS_API_LOGIN'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_ADMIN'); ?></td></tr>
							<tr><td><strong>Manager</strong></td><td><span class="badge bg-warning text-dark"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_NEEDS_GRANT'); ?></span></td><td><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PERMISSIONS_REASON_MANAGER'); ?></td></tr>
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
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_HEADING'); ?></h3>
					<p class="card-text small"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_CONNECTION_INTRO'); ?></p>
					<label for="csmcpforj-endpoint-url" class="form-label mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_ENDPOINT'); ?></strong></label>
					<div class="input-group mb-1">
						<input type="text" id="csmcpforj-endpoint-url" class="form-control font-monospace" value="<?php echo $endpoint; ?>" readonly onclick="this.select();">
						<button type="button" class="btn btn-primary text-white csmcpforj-copy-btn" data-target="csmcpforj-endpoint-url" title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_DASHBOARD_COPY_URL_BUTTON')); ?>">
							<span class="icon-copy" aria-hidden="true"></span>
						</button>
					</div>
					<small class="text-body-secondary d-block mb-3"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_ENDPOINT_HINT'); ?></small>

					<dl class="mb-0 small">
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_LOGIN'); ?></dt>
						<dd>
							<?php if ($this->oauthEnabled) : ?>
								<span class="badge bg-success"><?php echo Text::_('JON'); ?></span>
								<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_LOGIN_ON'); ?>
							<?php else : ?>
								<span class="badge bg-secondary"><?php echo Text::_('JOFF'); ?></span>
								<a href="<?php echo $this->escape($this->optionsUrl); ?>"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_OAUTH_ENABLE'); ?></a>
							<?php endif; ?>
						</dd>
						<dt><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN'); ?></dt>
						<dd class="mb-0">
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_VALUE'); ?>
							<a href="<?php echo $tokenUrl; ?>" target="_blank" rel="noopener"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_FIELD_TOKEN_LINK'); ?></a>
						</dd>
					</dl>
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
								<?php // Only the EDITABLE variant gets the panel. The readonly branch
								      // below displays an already-linked address — nothing to type,
								      // so flagging it as an input field would be a lie. ?>
								<div class="csmcpforj-field-panel">
									<label for="pro-email" class="csmcpforj-field-label">
										<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_EMAIL_LABEL'); ?>
										<span class="csmcpforj-field-required"><?php echo Text::_('COM_CSMCPFORJ_FIELD_REQUIRED'); ?></span>
									</label>
									<input type="email" id="pro-email" name="email" class="form-control"
										placeholder="<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_PRO_EMAIL_PLACEHOLDER'); ?>"
										value=""
										autocomplete="email"
										required>
								</div>
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
										<button type="submit" class="btn btn-danger text-white w-100"
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
							<button type="submit" class="btn btn-success text-white w-100">
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
								<button type="submit" class="btn btn-danger text-white w-100"
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

					<?php
					// Moved here from the old Connection card (Tim 2026-10-15: out of
					// place there). It checks cybersalt.com for new versions of MCP for
					// Joomla and its add-ons, so it belongs with the Cybersalt account.
					?>
					<hr class="my-3">
					<p class="card-text small mb-2"><?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_HINT'); ?></p>
					<form action="<?php echo Route::_('index.php?option=com_csmcpforj&task=dashboard.checkUpdatesNow'); ?>" method="post" class="m-0">
						<?php echo HTMLHelper::_('form.token'); ?>
						<button type="submit" class="btn btn-sm btn-primary text-white w-100">
							<span class="icon-refresh" aria-hidden="true"></span>
							<?php echo Text::_('COM_CSMCPFORJ_DASHBOARD_UPDATE_CHECK_BTN'); ?>
						</button>
					</form>
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

	// The token field appears once per connection way (setup prompt, config
	// file). Every copy shares one value: typing or pasting in any of them
	// fills the rest, and every preview that has a token placeholder
	// (data-csmcpforj-token-template) shows the token in place.
	var tokenInputs = Array.prototype.slice.call(document.querySelectorAll('[data-csmcpforj-token-input]'));
	var templates   = Array.prototype.slice.call(document.querySelectorAll('[data-csmcpforj-token-template]')).map(function (el) {
		return { el: el, placeholder: el.getAttribute('data-csmcpforj-token-template'), text: el.textContent };
	});

	function escapeHtml(s) {
		return s.replace(/[&<>"']/g, function(c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function currentToken() {
		for (var i = 0; i < tokenInputs.length; i++) {
			var v = (tokenInputs[i].value || '').trim();
			if (v !== '') { return v; }
		}
		return '';
	}

	// Mark where the token lands. The copy handler reads textContent, so the
	// <mark> never reaches the clipboard. With no token, the placeholder gets
	// the same highlight so the user can see which part will be replaced.
	function renderTemplates() {
		var token = currentToken();
		templates.forEach(function (t) {
			var parts  = t.text.split(t.placeholder);
			var marker = token !== ''
				? '<mark class="csmcpforj-token-mark">' + escapeHtml(token) + '</mark>'
				: '<mark class="csmcpforj-token-mark csmcpforj-token-mark-empty">' + escapeHtml(t.placeholder) + '</mark>';
			t.el.innerHTML = parts.map(escapeHtml).join(marker);
		});
	}

	function scopeOf(input) { return input.closest('.csmcpforj-step-body') || document; }

	function refreshChrome() {
		var hasToken = currentToken() !== '';
		tokenInputs.forEach(function (input) {
			var scope  = scopeOf(input);
			var clear  = scope.querySelector('[data-csmcpforj-token-clear]');
			var status = scope.querySelector('[data-csmcpforj-token-status]');
			if (clear)  { clear.classList.toggle('d-none', !hasToken); }
			if (status) { status.textContent = hasToken ? STATUS_SAVED : STATUS_EMPTY; }
		});
	}

	function setToken(value, source) {
		tokenInputs.forEach(function (input) { if (input !== source) { input.value = value; } });
		try {
			if (value === '') { window.localStorage.removeItem(STORAGE_KEY); }
			else { window.localStorage.setItem(STORAGE_KEY, value); }
		} catch (e) {}
		refreshChrome();
		renderTemplates();
	}

	try {
		var saved = window.localStorage.getItem(STORAGE_KEY);
		if (saved) { tokenInputs.forEach(function (input) { input.value = saved; }); }
	} catch (e) {
		// localStorage may be unavailable (private mode, etc.) - silent fallback
	}
	refreshChrome();
	renderTemplates();

	tokenInputs.forEach(function (input) {
		var onChange = function () { setToken((input.value || '').trim(), input); };
		input.addEventListener('input', onChange);
		input.addEventListener('change', onChange);
		input.addEventListener('blur', onChange);

		var scope  = scopeOf(input);
		var toggle = scope.querySelector('[data-csmcpforj-token-toggle]');
		var clear  = scope.querySelector('[data-csmcpforj-token-clear]');

		if (toggle) {
			toggle.addEventListener('click', function () {
				var type = input.type === 'password' ? 'text' : 'password';
				tokenInputs.forEach(function (i) { i.type = type; });
			});
		}
		if (clear) {
			clear.addEventListener('click', function () {
				input.value = '';
				setToken('', null);
				input.focus();
			});
		}
	});

	// Copy buttons. With data-csmcpforj-token-substitute set and a token
	// entered, the target's placeholder is replaced in the copied text. The
	// token never leaves the browser.
	document.querySelectorAll('[data-csmcpforj-copy]').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var el = document.getElementById(btn.getAttribute('data-csmcpforj-copy'));
			if (!el) return;
			var text = el.textContent || el.innerText;

			var substituted = false;
			var placeholder = el.getAttribute('data-csmcpforj-token-template');
			var token       = currentToken();
			if (btn.getAttribute('data-csmcpforj-token-substitute') === '1' && placeholder && token !== '') {
				text = text.split(placeholder).join(token);
				substituted = true;
			}

			var copiedLabel = substituted && btn.getAttribute('data-copied-substituted-label')
				? btn.getAttribute('data-copied-substituted-label')
				: btn.getAttribute('data-copied-label');
			var defaultLabel = btn.getAttribute('data-default-label');

			navigator.clipboard.writeText(text).then(function() {
				btn.innerHTML = '<span class="icon-checkmark" aria-hidden="true"></span> ' + copiedLabel;
				setTimeout(function() {
					btn.innerHTML = '<span class="icon-copy" aria-hidden="true"></span> ' + defaultLabel;
				}, 2500);
			}).catch(function() {
				// Fallback: select the text so the user can Ctrl+C.
				var range = document.createRange();
				range.selectNode(el);
				window.getSelection().removeAllRanges();
				window.getSelection().addRange(range);
			});
		});
	});

	// ─────────────────────────────────────────────────────────────────
	// Hover-with-delay reveal for blurred secrets (token pill + Pro email).
	//
	// Design rationale (Tim 2026-08-20): the blur alone defeats casual
	// screencasting exposure, but pure-CSS :hover reveal defeats itself the
	// moment a mouse pointer passes through the element while, say, moving
	// to click something else nearby. Adding a configurable delay (default 3s)
	// makes "reveal" an intentional act — the operator has to hold hover for
	// the full duration before the blur comes off, which is essentially never
	// what an accidental mouse-transit produces.
	//
	// Delay is component-option-driven (Dashboard HtmlView reads
	// hover_reveal_delay_seconds). 0 = immediate reveal (matches the
	// pre-delay behaviour for operators who don't want the friction).
	//
	// Visible countdown pill next to the blurred element (dynamically
	// created on mouseenter, updated per second, torn down on mouseleave
	// or reveal) tells the operator "yes, hover is registered — hold N
	// more seconds". Without it a slow reveal reads as "hover not working".
	//
	// Applies to: .csmcpforj-token-mark (the pasted-token pill inside the
	// prompt preview — but NOT its .csmcpforj-token-mark-empty placeholder
	// variant, which is intentionally always readable) AND #pro-email when
	// it's readonly (the post-activation email; editable form variant is
	// empty and needs no protection).
	(function () {
		// Both timing values PHP-interpolated at render time from the
		// component options (Dashboard HtmlView reads
		// hover_reveal_delay_seconds + hover_reveal_hide_seconds). Countdown
		// label template is also PHP-interpolated so translators can localise
		// it. No body attributes / no window globals, just baked-in constants.
		var DELAY_SECONDS = <?php echo (int) $this->hoverRevealDelay; ?>;
		var HIDE_SECONDS  = <?php echo (int) $this->hoverRevealHide; ?>;
		var COUNTDOWN_LABEL = <?php echo json_encode(Text::_('COM_CSMCPFORJ_DASHBOARD_HOVER_REVEAL_COUNTDOWN')); ?>;

		// Attaches hover-with-delay behaviour to a single blurred element.
		// The behaviour is stateful per-element: entering starts the timer,
		// leaving cancels it. If the timer completes, the element is marked
		// revealed and stays revealed for as long as the pointer is inside;
		// leaving re-blurs and the next entry starts a fresh countdown.
		function attachRevealBehaviour(el) {
			if (!el || el.dataset.csmcpforjRevealBound === '1') { return; }
			el.dataset.csmcpforjRevealBound = '1';

			// Both element types get wrapped in a scope span whose bounding
			// box exactly matches the element itself — that way the
			// absolute-positioned countdown pill can center on the element
			// (left: 50%, top: 50%, transform: translate(-50%, -50%))
			// regardless of type or size. Earlier version anchored the
			// input's countdown to the containing column which was much
			// bigger than the input; the pill positioned to the far right
			// of the column and off-screen (Tim 2026-08-21 caught this).
			var host = el;
			var isInput = el.tagName === 'INPUT';
			var scope = document.createElement('span');
			scope.className = 'csmcpforj-secret-hover-scope';
			// Block layout for the input so the wrapper matches the input's
			// full-width form-control layout; inline-block (default) for the
			// <mark> pill so it stays inline with surrounding prompt text.
			if (isInput) { scope.style.display = 'block'; }
			el.parentNode.insertBefore(scope, el);
			scope.appendChild(el);
			host = scope;

			var countdown = document.createElement('span');
			countdown.className = 'csmcpforj-secret-countdown';
			countdown.setAttribute('aria-hidden', 'true');
			host.appendChild(countdown);

			var tickTimer = null;    // per-second countdown display update
			var revealTimer = null;  // fires once, at end of delay, to reveal
			var hideTimer = null;    // fires once, after HIDE_SECONDS, to auto-blur back
			var remaining = 0;

			// Full teardown: cancel any pending timers, clear the countdown
			// pill, re-blur. Called on mouseleave AND on auto-hide firing.
			// The auto-hide case triggers a full teardown so re-entering
			// requires a fresh full-length countdown — this is the point
			// of auto-hide (unattended sessions shouldn't stay revealed).
			function cancel() {
				if (tickTimer !== null)   { clearInterval(tickTimer); tickTimer = null; }
				if (revealTimer !== null) { clearTimeout(revealTimer); revealTimer = null; }
				if (hideTimer !== null)   { clearTimeout(hideTimer);   hideTimer = null; }
				countdown.classList.remove('is-visible');
				countdown.textContent = '';
				el.classList.remove('csmcpforj-secret-revealed');
			}

			// Called at the end of the pre-reveal countdown. Applies the
			// reveal, hides the countdown pill, and — if auto-hide is
			// configured (HIDE_SECONDS > 0) — schedules the tear-down
			// that re-blurs after HIDE_SECONDS. If auto-hide is 0, the
			// reveal persists until mouseleave (pre-auto-hide behaviour).
			function performReveal() {
				if (tickTimer !== null) { clearInterval(tickTimer); tickTimer = null; }
				countdown.classList.remove('is-visible');
				countdown.textContent = '';
				el.classList.add('csmcpforj-secret-revealed');
				revealTimer = null;
				if (HIDE_SECONDS > 0) {
					hideTimer = setTimeout(function () {
						el.classList.remove('csmcpforj-secret-revealed');
						hideTimer = null;
					}, HIDE_SECONDS * 1000);
				}
			}

			function beginCountdown() {
				// Immediate-reveal mode (delay=0) skips the pre-reveal
				// countdown but still respects the auto-hide timer.
				if (DELAY_SECONDS <= 0) {
					cancel();
					performReveal();
					return;
				}
				// Skip empty/placeholder variants — they're not real secrets.
				if (el.classList.contains('csmcpforj-token-mark-empty')) { return; }
				// Reset any prior countdown state (defensive; mouseleave
				// should have cleared it already, but also handles the case
				// of re-entering while the auto-hide timer is still ticking).
				cancel();
				remaining = DELAY_SECONDS;
				countdown.textContent = COUNTDOWN_LABEL.replace('{seconds}', String(remaining));
				countdown.classList.add('is-visible');
				tickTimer = setInterval(function () {
					remaining -= 1;
					if (remaining <= 0) {
						countdown.textContent = '';
						countdown.classList.remove('is-visible');
					} else {
						countdown.textContent = COUNTDOWN_LABEL.replace('{seconds}', String(remaining));
					}
				}, 1000);
				revealTimer = setTimeout(performReveal, DELAY_SECONDS * 1000);
			}

			el.addEventListener('mouseenter', beginCountdown);
			el.addEventListener('mouseleave', cancel);
			// For the input specifically, focus should also start reveal
			// (a user tabbing to the field to copy or verify shouldn't
			// have to also mouse over it).
			if (isInput) {
				el.addEventListener('focus', beginCountdown);
				el.addEventListener('blur', cancel);
			}
		}

		// Bind now (for elements present at load) and re-bind whenever the
		// prompt preview repaints (the token mark gets re-created by the
		// token-input handler above whenever the operator types a new token).
		function bindAll() {
			document.querySelectorAll('.csmcpforj-token-mark:not(.csmcpforj-token-mark-empty)').forEach(attachRevealBehaviour);
			document.querySelectorAll('#pro-email[readonly]').forEach(attachRevealBehaviour);
		}
		bindAll();

		// Re-bind after the prompt preview's innerHTML gets rewritten by
		// the token-input handler. Cheap approach: observe the prompt
		// element (if present) for childList mutations and re-bind.
		var promptEl = document.getElementById('csmcpforj-prompt-preview')
			|| document.querySelector('[data-csmcpforj-prompt-preview]')
			|| document.getElementById('csmcpforj-prompt');
		if (promptEl && 'MutationObserver' in window) {
			var observer = new MutationObserver(bindAll);
			observer.observe(promptEl, { childList: true, subtree: true });
		}
	})();
});
</script>
