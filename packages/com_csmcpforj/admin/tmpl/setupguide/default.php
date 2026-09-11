<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/** @var \Cybersalt\Component\Csmcpforj\Administrator\View\Setupguide\HtmlView $this */

$endpoint     = htmlspecialchars($this->endpointUrl, ENT_QUOTES, 'UTF-8');
$host         = htmlspecialchars($this->host, ENT_QUOTES, 'UTF-8');
$dashboardUrl = htmlspecialchars($this->dashboardUrl, ENT_QUOTES, 'UTF-8');
$tokenUrl     = htmlspecialchars($this->tokenProfileUrl, ENT_QUOTES, 'UTF-8');
$codexServerName = $this->codexServerName;
$codexEnvName    = $this->codexTokenEnvironmentName;

// Raw angle brackets on purpose. The whole snippet gets run through
// htmlspecialchars() below when it's echoed into the <code> element, which
// encodes '<' → '&lt;' etc. for correct on-screen rendering. The DOM's
// textContent (what the Copy button reads to build the clipboard payload)
// decodes back to the raw '<' characters, so users paste clean text — no
// literal '&lt;' entities leaking into their config files. If this string
// was pre-encoded ('&lt;...&gt;') instead, htmlspecialchars would then
// double-encode the '&' to '&amp;', browsers would render '&amp;lt;' as
// literal '&lt;' on screen, and users would paste garbage. (Tim caught
// this 2026-08-15.)
$tokenPlaceholder = '<PASTE YOUR JOOMLA API TOKEN HERE>';

// The Claude Desktop wrapper approach — two files.
//
// Claude Desktop 1.30096 (Aug 2026) has strict config-schema validation
// that rejects both (a) the native `type: http` shape and (b) `mcp-remote`
// with `--header "Authorization: Bearer ..."` inline in args. Confirmed by
// Tim + Claude Code diagnostic pass on 2026-08-15: both patterns produce
// the "Some MCP servers could not be loaded" popup on startup. The wrapper
// script pattern (put the mcp-remote command in a small .cmd file, then
// have Claude Desktop's config just reference the wrapper) sidesteps the
// validator entirely because from Claude Desktop's perspective it's just
// a plain command with no args to inspect. Token security profile is
// unchanged — the wrapper file lives at the same user-readable location
// as claude_desktop_config.json itself.

$snippetClaudeDesktopWrapper = <<<HTML
@echo off
"C:\\Program Files\\nodejs\\npx.cmd" -y mcp-remote {$endpoint} --header "Authorization: Bearer {$tokenPlaceholder}"
HTML;

$snippetClaudeDesktopConfig = <<<HTML
{
  "mcpServers": {
    "joomla-{$host}": {
      "command": "C:\\\\Users\\\\YOUR_WINDOWS_USERNAME\\\\claude-mcp-joomla-{$host}.cmd"
    }
  }
}
HTML;

$snippetClaudeCode = <<<HTML
claude mcp add --transport http joomla-{$host} \
  {$endpoint} \
  --header "Authorization: Bearer {$tokenPlaceholder}"
HTML;

$snippetCursor = <<<HTML
{
  "mcpServers": {
    "joomla-{$host}": {
      "url": "{$endpoint}",
      "headers": {
        "Authorization": "Bearer {$tokenPlaceholder}"
      }
    }
  }
}
HTML;

$snippetChatGpt = <<<HTML
URL:    {$endpoint}
Header: Authorization
Value:  Bearer {$tokenPlaceholder}
HTML;

$snippetCodexWindows = <<<POWERSHELL
[Environment]::SetEnvironmentVariable('{$codexEnvName}', '{$tokenPlaceholder}', 'User')
POWERSHELL;

$snippetCodexUnix = <<<SHELL
export {$codexEnvName}='{$tokenPlaceholder}'
SHELL;

$snippetCodexCli = <<<SHELL
codex mcp add {$codexServerName} --url '{$this->endpointUrl}' --bearer-token-env-var {$codexEnvName}
SHELL;

$snippetCodexToml = <<<TOML
[mcp_servers.{$codexServerName}]
url = "{$this->endpointUrl}"
env_http_headers = { "X-Joomla-Token" = "{$codexEnvName}" }
default_tools_approval_mode = "writes"
TOML;

// Gemini CLI / Gemini Code Assist — reads ~/.gemini/settings.json.
// Uses `httpUrl` (not `url` — Cursor and Claude Desktop use `url` for
// stdio-bridged servers; Gemini's native HTTP MCP client uses `httpUrl`
// as a distinct key). Bearer token goes into the same `headers` block.
$snippetGemini = <<<HTML
{
  "mcpServers": {
    "joomla-{$host}": {
      "httpUrl": "{$endpoint}",
      "headers": {
        "Authorization": "Bearer {$tokenPlaceholder}"
      }
    }
  }
}
HTML;
?>
<style>
/* Setup Guide layout — restructured 2026-08-13 to lead with the easy
 * (Dashboard copy-prompt) path per Tim's feedback that the prior version
 * was too technical for the non-technical audience MCP for J targets. */
.csmcpforj-setup-nav {
	position: sticky;
	top: 1rem;
}
.csmcpforj-setup-section {
	scroll-margin-top: 1rem;
}
.csmcpforj-setup-section h3 {
	margin-top: 0;
}
.csmcpforj-setup-section pre {
	background: var(--bs-body-bg);
	color: var(--bs-emphasis-color);
	border: 1px solid var(--bs-border-color);
	border-radius: .25rem;
	padding: .75rem 1rem;
	margin: 0 0 .5rem;
	overflow-x: auto;
	font-size: .875rem;
}
.csmcpforj-setup-section code {
	background: var(--bs-secondary-bg);
	color: var(--bs-emphasis-color);
	padding: .1em .35em;
	border-radius: .2em;
	font-size: .95em;
}
.csmcpforj-setup-section pre code {
	background: transparent;
	padding: 0;
	border: 0;
	font-size: 1em;
}
/* The Easy Setup card gets a primary-blue accent so it's visually the FIRST
 * thing the eye lands on when the page loads. Uses the "colored border +
 * colored title on neutral bg" pattern (feedback_advisory_box_design_pattern)
 * with a bigger 6px border for extra visual weight. Primary blue (Bootstrap
 * #0d6efd) matches the CTA button below and reads as the canonical
 * Joomla-admin action colour (Tim 2026-08-15 — the earlier info-cyan #4dd0e1
 * felt off in Atum light mode). */
.csmcpforj-easy-setup {
	background: var(--bs-tertiary-bg);
	border: 1px solid var(--bs-border-color);
	border-left: 6px solid #0d6efd;
	border-radius: .25rem;
}
.csmcpforj-easy-setup h3 {
	color: #0d6efd;
	margin-bottom: .5rem;
}
.csmcpforj-easy-setup .csmcpforj-easy-cta {
	background: #0d6efd;
	color: #fff;
	font-size: 1.05rem;
	padding: .75rem 1.5rem;
}
.csmcpforj-easy-setup .csmcpforj-easy-cta:hover {
	background: #0b5ed7;
	color: #fff;
}
details.csmcpforj-setup-faq,
details.csmcpforj-setup-troubleshoot,
details.csmcpforj-setup-advanced {
	background: var(--bs-tertiary-bg);
	border: 1px solid var(--bs-border-color);
	border-radius: .25rem;
	padding: .5rem .75rem;
	margin-bottom: .5rem;
}
details.csmcpforj-setup-troubleshoot { border-left: 4px solid #ffc107; }
details.csmcpforj-setup-advanced     { border-left: 4px solid var(--bs-secondary-color); padding: 1rem 1.25rem; }
details.csmcpforj-setup-advanced[open] { padding-bottom: 1.5rem; }
details.csmcpforj-setup-faq summary,
details.csmcpforj-setup-troubleshoot summary,
details.csmcpforj-setup-advanced summary {
	cursor: pointer;
	font-weight: 600;
	color: var(--bs-emphasis-color);
	list-style: none;
}
details.csmcpforj-setup-advanced summary {
	font-size: 1.1rem;
}
details.csmcpforj-setup-faq summary::-webkit-details-marker,
details.csmcpforj-setup-troubleshoot summary::-webkit-details-marker,
details.csmcpforj-setup-advanced summary::-webkit-details-marker { display: none; }
details.csmcpforj-setup-faq summary::before,
details.csmcpforj-setup-troubleshoot summary::before,
details.csmcpforj-setup-advanced summary::before {
	content: '▸';
	display: inline-block;
	margin-right: .35em;
	transition: transform .15s ease;
	color: var(--bs-secondary-color);
}
details.csmcpforj-setup-faq[open] summary::before,
details.csmcpforj-setup-troubleshoot[open] summary::before,
details.csmcpforj-setup-advanced[open] summary::before {
	transform: rotate(90deg);
}
.csmcpforj-setup-copy-btn.is-copied {
	background-color: var(--bs-success);
	border-color: var(--bs-success);
}
.csmcpforj-token-warning {
	color: var(--bs-warning-text-emphasis);
	background: var(--bs-warning-bg-subtle);
	border-left: 4px solid #ffc107;
	padding: .5rem .75rem;
	border-radius: .25rem;
	font-size: .875rem;
}
</style>

<div class="container-fluid">
	<div class="row">
		<div class="col-lg-9">

			<div class="card mb-3 csmcpforj-setup-section" id="section-intro">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_INTRO_HEADING'); ?></h3>
					<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_INTRO_BODY'); ?></p>
				</div>
			</div>

			<div class="card mb-3 csmcpforj-easy-setup csmcpforj-setup-section" id="section-easy">
				<div class="card-body">
					<h3 class="card-title">
						<?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_HEADING'); ?>
					</h3>
					<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_BODY'); ?></p>
					<ol class="mb-3">
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_STEP1'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_STEP2'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_STEP3'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_STEP4'); ?></li>
					</ol>
					<p class="mb-3"><a href="<?php echo $dashboardUrl; ?>" class="btn csmcpforj-easy-cta">
						<span class="icon-dashboard" aria-hidden="true"></span>
						<?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_CTA'); ?>
					</a></p>
					<p class="mb-0"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_EASY_HINT'); ?></small></p>
				</div>
			</div>

			<div class="card mb-3 csmcpforj-setup-section" id="section-token">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_HEADING'); ?></h3>
					<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_BODY'); ?></p>
					<ol class="mb-3">
						<li class="mb-2"><?php echo Text::sprintf('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_STEP1', '<a href="' . $tokenUrl . '" target="_blank" rel="noopener">', '</a>'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_STEP2'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_STEP3'); ?></li>
						<li class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_STEP4'); ?></li>
					</ol>

					<div class="csmcpforj-token-warning mb-3">
						<strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_WARNING_TITLE'); ?></strong>
						<span><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_WARNING_BODY'); ?></span>
					</div>

					<label for="csmcpforj-setupguide-token-input" class="form-label fw-bold mb-1">
						<?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_INPUT_LABEL'); ?>
					</label>
					<div class="input-group mb-2">
						<span class="input-group-text"><span class="icon-key" aria-hidden="true"></span></span>
						<input type="password" class="form-control" id="csmcpforj-setupguide-token-input" placeholder="sha256:42:abc123def456..." autocomplete="off" data-csmcpforj-token-input>
						<button type="button" class="btn btn-outline-secondary" data-csmcpforj-token-toggle title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_TOGGLE_SHOW')); ?>">
							<span class="icon-eye" aria-hidden="true"></span>
						</button>
						<button type="button" class="btn btn-outline-danger d-none" data-csmcpforj-token-clear title="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_CLEAR')); ?>">
							<span class="icon-trash" aria-hidden="true"></span>
						</button>
					</div>
					<p class="mb-0"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TOKEN_INPUT_HINT'); ?></small></p>
				</div>
			</div>

			<div class="csmcpforj-setup-section" id="section-advanced">
				<details class="csmcpforj-setup-advanced">
					<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_SUMMARY'); ?></summary>
					<div class="mt-3">

						<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_INTRO'); ?></p>

						<h5 class="mt-4"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_ENDPOINT_HEADING'); ?></h5>
						<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_ENDPOINT_BODY'); ?></p>
						<pre id="csmcpforj-setupguide-endpoint"><code><?php echo $endpoint; ?></code></pre>

						<h5 class="mt-4"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_CLIENT_PICKER_HEADING'); ?></h5>
						<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_ADVANCED_CLIENT_PICKER_BODY'); ?></p>

						<ul class="nav nav-tabs mb-3" role="tablist">
							<li class="nav-item" role="presentation">
								<button class="nav-link active" id="tab-codex" data-bs-toggle="tab" data-bs-target="#pane-codex" type="button" role="tab"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_TAB_CODEX'); ?></button>
							</li>
							<li class="nav-item" role="presentation">
								<button class="nav-link" id="tab-claude" data-bs-toggle="tab" data-bs-target="#pane-claude" type="button" role="tab"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_TAB_CLAUDE'); ?></button>
							</li>
							<li class="nav-item" role="presentation">
								<button class="nav-link" id="tab-cursor" data-bs-toggle="tab" data-bs-target="#pane-cursor" type="button" role="tab"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_TAB_CURSOR'); ?></button>
							</li>
							<li class="nav-item" role="presentation">
								<button class="nav-link" id="tab-chatgpt" data-bs-toggle="tab" data-bs-target="#pane-chatgpt" type="button" role="tab"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_TAB_CHATGPT'); ?></button>
							</li>
							<li class="nav-item" role="presentation">
								<button class="nav-link" id="tab-gemini" data-bs-toggle="tab" data-bs-target="#pane-gemini" type="button" role="tab"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_TAB_GEMINI'); ?></button>
							</li>
						</ul>

						<div class="tab-content">
							<div class="tab-pane fade show active" id="pane-codex" role="tabpanel">
								<h5><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_HEADING'); ?></h5>
								<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_BODY'); ?></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_WINDOWS'); ?></strong></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-codex-windows" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetCodexWindows, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-codex-windows" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_WINDOWS_HINT'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_UNIX'); ?></strong></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-codex-unix" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetCodexUnix, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-codex-unix" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>

								<p class="mt-3 mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_ADD'); ?></strong></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-codex-cli" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetCodexCli, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-codex-cli" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_VERIFY'); ?></small></p>

								<div class="csmcpforj-token-warning mb-2">
									<strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_FALLBACK_TITLE'); ?></strong>
									<span><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CODEX_FALLBACK_BODY'); ?></span>
								</div>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-codex-toml" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetCodexToml, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-codex-toml" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
							</div>

							<div class="tab-pane fade" id="pane-claude" role="tabpanel">

								<div class="csmcpforj-token-warning mb-3">
									<strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_HEADS_UP_TITLE'); ?></strong>
									<span><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_HEADS_UP_BODY'); ?></span>
								</div>

								<h5><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_HEADING'); ?></h5>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_BODY'); ?></p>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_WHY_WRAPPER'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_PREREQ_HEADING'); ?></strong></p>
								<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_PREREQ_BODY'); ?></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP1_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP1_BODY'); ?></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-claude-desktop-wrapper" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetClaudeDesktopWrapper, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-claude-desktop-wrapper" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP1_HINT'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP2_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP2_BODY'); ?></p>
								<ul class="mb-2">
									<li class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_MAC_LABEL'); ?></strong> <code><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_MAC_PATH'); ?></code></li>
									<li class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_WIN_LABEL'); ?></strong> <code><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_WIN_PATH'); ?></code></li>
									<li class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_LINUX_LABEL'); ?></strong> <code><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_LINUX_PATH'); ?></code></li>
								</ul>
								<p class="mb-2"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_EDIT_CONFIG_TIP'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP3_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP3_BODY'); ?></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-claude-desktop-config" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetClaudeDesktopConfig, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-claude-desktop-config" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP3_HINT'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP4_HEADING'); ?></strong></p>
								<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_DESKTOP_STEP4_BODY'); ?></p>

								<h5 class="mt-4"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_CODE_HEADING'); ?></h5>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_CODE_BODY'); ?></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-claude-code" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetClaudeCode, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-claude-code" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>

								<h5 class="mt-4"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_AI_HEADING'); ?></h5>
								<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CLAUDE_AI_BODY'); ?></p>
							</div>

							<div class="tab-pane fade" id="pane-cursor" role="tabpanel">
								<h5><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CURSOR_HEADING'); ?></h5>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CURSOR_BODY'); ?></p>
								<p class="mb-2"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CURSOR_LOCATION'); ?></small></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-cursor" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetCursor, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-cursor" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
							</div>

							<div class="tab-pane fade" id="pane-chatgpt" role="tabpanel">
								<h5><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CHATGPT_HEADING'); ?></h5>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CHATGPT_BODY'); ?></p>
								<p class="mb-2"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_CHATGPT_TIER_NOTE'); ?></small></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-chatgpt" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetChatGpt, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-chatgpt" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
							</div>

							<div class="tab-pane fade" id="pane-gemini" role="tabpanel">
								<h5><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_HEADING'); ?></h5>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_BODY'); ?></p>
								<p class="mb-2"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_SCOPE_NOTE'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP1_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP1_BODY'); ?></p>
								<ul class="mb-3">
									<li class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_GLOBAL_LABEL'); ?></strong> <code><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_GLOBAL_PATH'); ?></code></li>
									<li class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_PROJECT_LABEL'); ?></strong> <code><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_PROJECT_PATH'); ?></code></li>
								</ul>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP2_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP2_BODY'); ?></p>
								<div class="position-relative">
									<pre id="csmcpforj-snippet-gemini" data-csmcpforj-snippet><code><?php echo htmlspecialchars($snippetGemini, ENT_QUOTES, 'UTF-8'); ?></code></pre>
									<button type="button" class="btn btn-sm btn-primary text-white csmcpforj-setup-copy-btn position-absolute top-0 end-0 m-2" data-csmcpforj-copy="csmcpforj-snippet-gemini" data-csmcpforj-token-substitute="1" data-default-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY')); ?>" data-copied-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED')); ?>" data-copied-substituted-label="<?php echo $this->escape(Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPIED_WITH_TOKEN')); ?>">
										<span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_COPY'); ?>
									</button>
								</div>
								<p class="mb-3"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP2_HINT'); ?></small></p>

								<p class="mb-1"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP3_HEADING'); ?></strong></p>
								<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_STEP3_BODY'); ?></p>

								<p class="mt-3 mb-0"><small class="text-body-secondary"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENT_GEMINI_WEB_UI_NOTE'); ?></small></p>
							</div>

						</div>

						<p class="mt-3 mb-0 text-body-secondary"><small><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_CLIENTS_MORE_COMING'); ?></small></p>
					</div>
				</details>
			</div>

			<div class="card mb-3 csmcpforj-setup-section" id="section-security">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_HEADING'); ?></h3>
					<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_BODY'); ?></p>

					<h5 class="mt-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_SHARE_HEADING'); ?></h5>
					<p class="mb-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_SHARE_BODY'); ?></p>
					<ul class="mb-2">
						<li class="mb-2"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_OPTION_A_TITLE'); ?></strong> &mdash; <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_OPTION_A_BODY'); ?></li>
						<li class="mb-2"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_OPTION_B_TITLE'); ?></strong> &mdash; <?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_OPTION_B_BODY'); ?></li>
					</ul>

					<h5 class="mt-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_REVOKE_HEADING'); ?></h5>
					<p class="mb-0"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_SECURITY_REVOKE_BODY'); ?></p>
				</div>
			</div>

			<div class="card mb-3 csmcpforj-setup-section" id="section-troubleshoot">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLESHOOT_HEADING'); ?></h3>
					<p class="mb-3"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLESHOOT_BODY'); ?></p>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_401_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_401_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_406_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_406_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_EMPTY_TOOLS_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_EMPTY_TOOLS_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_404_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_404_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_WAF_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_WAF_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_EDIT_CONFIG_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_EDIT_CONFIG_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_OAUTH_DIALOG_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_OAUTH_DIALOG_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_SKIPPED_INVALID_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_SKIPPED_INVALID_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-troubleshoot">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_TRAY_RESTART_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_TROUBLE_TRAY_RESTART_BODY'); ?></div>
					</details>
				</div>
			</div>

			<div class="card mb-3 csmcpforj-setup-section" id="section-faq">
				<div class="card-body">
					<h3 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_HEADING'); ?></h3>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_ANONYMOUS_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_ANONYMOUS_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_MULTIPLE_TOKENS_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_MULTIPLE_TOKENS_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_RATE_LIMIT_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_RATE_LIMIT_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_HTTPS_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_HTTPS_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_ROTATE_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_ROTATE_BODY'); ?></div>
					</details>

					<details class="csmcpforj-setup-faq">
						<summary><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_OTHER_CLIENTS_SUMMARY'); ?></summary>
						<div class="mt-2"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_FAQ_OTHER_CLIENTS_BODY'); ?></div>
					</details>
				</div>
			</div>

		</div>

		<div class="col-lg-3">
			<nav class="csmcpforj-setup-nav">
				<div class="card">
					<div class="card-body">
						<h5 class="card-title"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_HEADING'); ?></h5>
						<ul class="nav flex-column">
							<li class="nav-item"><a class="nav-link" href="#section-easy"><strong><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_EASY'); ?></strong></a></li>
							<li class="nav-item"><a class="nav-link" href="#section-token"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_TOKEN'); ?></a></li>
							<li class="nav-item"><a class="nav-link" href="#section-advanced"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_ADVANCED'); ?></a></li>
							<li class="nav-item"><a class="nav-link" href="#section-security"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_SECURITY'); ?></a></li>
							<li class="nav-item"><a class="nav-link" href="#section-troubleshoot"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_TROUBLESHOOT'); ?></a></li>
							<li class="nav-item"><a class="nav-link" href="#section-faq"><?php echo Text::_('COM_CSMCPFORJ_SETUPGUIDE_NAV_FAQ'); ?></a></li>
						</ul>
					</div>
				</div>
			</nav>
		</div>

	</div>
</div>

<script>
(function () {
	'use strict';
	var STORAGE_KEY = 'csmcpforj-token';
	var TOKEN_PLACEHOLDER = '<PASTE YOUR JOOMLA API TOKEN HERE>';

	var tokenInput  = document.querySelector('[data-csmcpforj-token-input]');
	var toggleBtn   = document.querySelector('[data-csmcpforj-token-toggle]');
	var clearBtn    = document.querySelector('[data-csmcpforj-token-clear]');

	function readSaved() {
		try { return window.localStorage.getItem(STORAGE_KEY) || ''; }
		catch (e) { return ''; }
	}
	function writeSaved(v) {
		try {
			if (v === '') { window.localStorage.removeItem(STORAGE_KEY); }
			else { window.localStorage.setItem(STORAGE_KEY, v); }
		} catch (e) { /* silent */ }
	}
	function refreshClearVisibility() {
		if (!clearBtn || !tokenInput) return;
		if (tokenInput.value.length > 0) { clearBtn.classList.remove('d-none'); }
		else { clearBtn.classList.add('d-none'); }
	}

	if (tokenInput) {
		var saved = readSaved();
		if (saved) { tokenInput.value = saved; }
		refreshClearVisibility();
		tokenInput.addEventListener('input', function () {
			writeSaved(tokenInput.value);
			refreshClearVisibility();
		});
	}
	if (toggleBtn && tokenInput) {
		toggleBtn.addEventListener('click', function () {
			tokenInput.type = (tokenInput.type === 'password') ? 'text' : 'password';
		});
	}
	if (clearBtn && tokenInput) {
		clearBtn.addEventListener('click', function () {
			tokenInput.value = '';
			writeSaved('');
			refreshClearVisibility();
			tokenInput.focus();
		});
	}

	document.querySelectorAll('.csmcpforj-setup-copy-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var targetId = btn.getAttribute('data-csmcpforj-copy');
			var target = document.getElementById(targetId);
			if (!target) return;
			var text = target.textContent || '';
			var substituted = false;
			if (btn.getAttribute('data-csmcpforj-token-substitute') === '1' && tokenInput) {
				var tok = tokenInput.value.trim();
				if (tok.length > 0) {
					text = text.split(TOKEN_PLACEHOLDER).join(tok);
					substituted = true;
				}
			}
			navigator.clipboard.writeText(text).then(function () {
				var originalHtml = btn.innerHTML;
				btn.classList.add('is-copied');
				btn.innerHTML = '<span class="icon-check" aria-hidden="true"></span> ' + (substituted
					? btn.getAttribute('data-copied-substituted-label')
					: btn.getAttribute('data-copied-label'));
				setTimeout(function () {
					btn.classList.remove('is-copied');
					btn.innerHTML = originalHtml;
				}, 2500);
			});
		});
	});
})();
</script>
