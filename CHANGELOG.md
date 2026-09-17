# Changelog

## 🚀 Version 2.7.5 (September 14, 2026)

### 🐛 Fixed — the download id now reaches the update server, not just the download

- 2.7.4 stamped the dlid into `#__update_sites.extra_query`, which fixed downloads. It did **not** fix update *discovery*, because **Joomla never appends `extra_query` to the update-XML request** — only to the download URL. `Updater::findUpdates()` copies `extra_query` onto the parsed update record for the installer to use afterwards; the XML itself is fetched from the bare `location`.
- The consequence was invisible until cs-release-manager started using the dlid: with no identity on the XML request, the update server could not tell an entitled site from an unentitled one, and briefly told **every** polling site its update was unavailable (fixed on that side in cs-release-manager 1.12.2).
- `syncUpdateSiteDlid()` now writes the dlid into the update site's **`location`** as well as `extra_query` — location for discovery, extra_query for the download. Any existing `dlid=` in the location is stripped first, so re-linking with a different account cannot leave two.
- Downloads were already working from 2.7.4; this restores an accurate answer to *"why can't I install this?"* for sites that genuinely cannot.

## 🚀 Version 2.7.4 (September 14, 2026)

### 🐛 Fixed — 2.7.3's update-site stamping did not actually run on most sites

- 2.7.3 added `syncUpdateSiteDlid()` but called it only *after* a successful re-verification. `refreshIfStale()` returns early whenever the throttle window has not elapsed — which is the normal state for any site verified in the last 24 hours — so on those sites the stamp never happened and Pro add-on updates stayed broken.
- The dlid does not depend on verification being due. The stamp now runs as soon as the site is known to be linked, before the throttle check. The write is guarded so it is a no-op when the value already matches.
- Caught by blanking `extra_query` on a live site, loading the dashboard, and finding it still empty — the fix 2.7.3 shipped was real but unreachable.

## 🚀 Version 2.7.3 (September 14, 2026) — superseded by 2.7.4, see above

### 🐛 Fixed — Pro add-on updates failed for EVERY site, entitled or not

- **Extensions → Update could not download any Pro add-on, on any site.** Joomla reported its own `COM_INSTALLER_PACKAGE_DOWNLOAD_FAILED` — *"Failed to download package. Download it and install manually from &lt;url&gt;"* — quoting a URL that also fails, because it is the same credential-less URL that just refused.
- **Cause: `#__update_sites.extra_query` was never populated.** Our update XML's `<downloadurl>` carries no credentials — it cannot, since the XML is generated per-element, not per-site. Joomla's mechanism for exactly this is `extra_query`, which it appends both to the update-XML request and to the download URL; it is how every commercial Joomla extension ships a download ID. Leaving it empty meant `api.download` was called with no `dlid` and returned **HTTP 400**. cs-release-manager was behaving as designed — its own code comment says members-only packages *"rely on Joomla appending extra_query"* — the client side simply never wrote it.
- **This was not an entitlement bug.** It hit paying, fully entitled customers identically. It stayed hidden because the in-admin catalog installs through `install_extension` with an explicit `dlid` URL, which bypasses Joomla's updater entirely — so the catalog worked while Joomla's own update screen did not.
- **New `ProActivationHelper::syncUpdateSiteDlid()`** stamps `dlid=<installation_id>:<email_hash>` onto every cs-mcp-for-j add-on update site, and is called whenever the dlid can change: on activation, on entitlement refresh, and on deactivate (where it clears the credential rather than leaving a stale one behind). Update sites are matched on the same location pattern `checkUpdatesNow()` uses, so add-ons installed by any route are covered — including a manual Install-from-URL that never went through the catalog.
- Existing sites self-heal on their next dashboard or catalog load after updating. Verified against a live entitled site: the download Joomla will now perform returns **HTTP 200, `application/zip`, 305,620 bytes**.

> Companion release: **cs-release-manager 1.12.1**, which uses the now-arriving `dlid` to explain in the update XML *why* a site that still cannot download is being refused — rather than leaving Joomla to show a dead end.

## 🚀 Version 2.7.2 (September 14, 2026)

### 🐛 Fixed — newly published Pro add-ons showed as "Get it →" on sites that already own them

- **Every time a new Pro add-on ships, already-linked sites showed it as needing purchase** — including All-Access accounts unambiguously entitled to it. Not a licensing bug: `api.verifyaccess` returned the correct, larger list the whole time. The stale copy was local.
- `entitled_elements` is a **snapshot**, computed server-side when the account is linked and cached in component params. Anything published afterwards is simply not in it. Confirmed in the field 2026-09-14 while shipping the EasyBlog add-on: a site linked at 18:49 could not see an add-on published at 20:35 until its cached list was refreshed by hand — 16 elements cached, 17 actually entitled.
- **2.7.1's self-heal could not catch this.** It fires only when the cached list is *empty* (verified under a pre-entitlement build). A list that is populated but out of date looks healthy, and that is precisely the case that recurs on every release.
- **New: `ProActivationHelper::refreshIfCatalogOffersUnknown()`.** The catalog view now re-verifies when the catalog offers a Pro element this site has never heard of. The unknown set is fingerprinted and the fingerprint stored, so the probe is **self-limiting**: a newly published add-on changes the fingerprint exactly once and costs exactly one round trip, while a site that genuinely does not own an add-on asks once and then stops — rather than re-verifying on every page view for an answer that will not change.
- The fingerprint is cleared on deactivate alongside the other Pro params, so an unlink → re-link with a different or upgraded account re-probes instead of trusting the old answer.

## 🚀 Version 2.7.1 (September 11, 2026)

Add-on catalog + licensing refinements: the catalog now understands **per-add-on entitlement** (Install vs "Get it →" per add-on, so à-la-carte buyers see exactly what they own), the Pro-activation card is reframed as **"Link your Cybersalt Extensions account,"** every Pro add-on card carries a **vendor-dependency notice**, and the bundled fallback catalog no longer 404s installs. Builds on 2.7.0 (Codex support). Companion release: cs-release-manager 1.11.6.

### ✨ New — per-add-on entitlement in the catalog (GitHub #21 / #25, Phase 2)

- **Browse MCP Add-Ons now shows Install vs "Get it →" per add-on**, based on what the linked account actually owns — not one umbrella Pro verdict. An à-la-carte buyer (e.g. only "MCP for 4SEO") sees 4SEO as installable and the other Pro add-ons as **"Get it →"** (linking to the membership store), and is no longer told they have no membership (#21, the "four denials in thirteen minutes" bug).
- **cs-release-manager `api.verifyaccess` gained an additive `entitled_elements` list** — the extension_elements the account can install, resolved across the whole catalog from the account's group memberships (free add-ons always included; owned Pro add-ons via à-la-carte plan group OR All-Access). Backward-compatible: old clients ignore the field. Verified end-to-end: Dragan (à-la-carte) → 4SEO + free only; an All-Access account → all 16.
- **cs-mcp-for-j** stores/exposes it (`ProActivationHelper::getEntitledElements()` / `isEntitledTo()` / `isLinked()`); the catalog view keys per-add-on `has_pro_membership` off it and **self-heals** a stale/empty list on load (so an existing install verified under the pre-entitlement build fixes itself on first catalog view).
- The dead "Pro — manual install" locked pill is now an actionable **"Get it →"** link to `/membership-plans`.
- **Companion release: cs-release-manager v1.11.6** (the `entitled_elements` resolver in `AccessCheckHelper`).

### ✨ Changed — "Link your Cybersalt Extensions account" + Pro add-on vendor-dependency notice (GitHub #21 / #25, Phase 1)

- **Dashboard rename.** The "Pro Membership Activation" card is now **"Link your Cybersalt Extensions account"** (button "Activate Pro" → **"Link account"**, "Membership email" → **"Purchase email"**), and the intro is reworded so a customer who bought a single à-la-carte add-on is no longer told they need a "Pro membership." One link step (enter the email you purchased with) covers both All-Access and individual-add-on buyers.
- **Vendor-dependency notice on Pro add-on cards.** Every Pro add-on in Browse MCP Add-Ons now shows: *"Adds MCP tools for &lt;Extension&gt; (from &lt;Vendor&gt;). You'll need that extension itself — a separate product purchased from the vendor — installed on your site for these tools to work."* Rendered from each add-on's `target_extension` (name + vendor). Prevents the "I bought the add-on, where's the extension?" confusion (Leandro / customer feedback).

### 🐛 Fixed — catalog fallback pinned dead add-on versions (GitHub #20)

- **Bundled fallback catalog no longer 404s add-on installs.** `catalog.fallback.json` had hardcoded the 4SEO and RSTicketsPro add-ons at **v1.8.0** in their `download_url`, but cs-release-manager only ever published **v1.10.2** for both — so any catalog install that fell back to the bundled file (when the live `api.catalog` fetch failed or was cache-stale) asked cs-release-manager for a version that did not exist and got *"Requested version not found."* Reported by Dragan Subotic (first MCP for 4SEO customer); his workaround was to drop the version parameter, which pulls the latest and works.
- **Fix is structural, not a version bump.** The `&version=` pin is now stripped from every `download_url` in the fallback so it always installs the latest — matching the proven workaround and immune to future drift. The bundled file was also **regenerated from the live catalog**, so it now carries all current add-ons (16, was 4) with the AI-neutral *"Adds MCP tools for…"* descriptions instead of the stale *"Adds Claude tools for…"* text.
- **build.ps1 now refreshes the fallback from the live catalog on every build** (fetch → strip version pins → relabel source → validate → write), with a graceful keep-the-committed-file fallback if the fetch fails. The bundled fallback can no longer silently drift out of date.

## 🚀 Version 2.7.0 (September 5, 2026)

### 🔌 New — Native Codex connection support

- Added a dedicated **Codex** tab to the Setup Guide with site-specific Windows PowerShell, macOS/Linux, `codex mcp add`, and `X-Joomla-Token` fallback snippets.
- Tokens are referenced by a generated environment-variable name instead of being stored in Codex's `config.toml`; the existing browser-only copy substitution remains available for the environment setup commands.
- Added concise server-wide `instructions` to the MCP `initialize` response so Codex receives prompt-injection and write-approval guidance during the native handshake.
- Made the Dashboard setup prompt client-neutral and added a permanent Codex registration path alongside Claude Code.
- Documented Codex registration, verification, least-privilege access, read-only mode, and the cPanel/PHP-FPM fallback in the README.

### 📦 Build

- Bumped the package and component manifests to 2.7.0 and the System plugin to 1.15.0. The installable package continues to bundle the component plus the required System and Web Services plugins.

## 🚀 Version 2.6.0 (August 21, 2026)

Ships two new capability classes: a **Support** admin view for submitting questions and ideas directly to `support@cybersalt.com` with tier-aware routing, and a **screencasting-safe secret-reveal system** for the Dashboard's Joomla API token and Pro membership email — blur by default with configurable hover-with-delay + auto-hide.

### 📮 New — Support view

Fourth admin submenu item alongside Dashboard / Browse MCP Add-Ons / Setup Guide. Cross-view toolbar buttons on all three existing views also link here.

- **Tier-aware banner** at the top drives the framing:
  - **Pro members** see a green priority-support banner: *"You have an active MCP for J Pro membership — thank you! Pro members get priority support. Ask us anything — bug reports, feature requests, questions, ideas — all welcome."*
  - **Community (Free) users** see a friendly blue banner: *"We love hearing from you. Support for the free version is on a best-effort basis... If you need guaranteed response times or priority attention, consider [an MCP for J Pro membership](https://www.cybersalt.com/membership-plans)"* &mdash; with a real hotlink to the OS Membership Pro plans page where users actually buy.
- **Form fields**: type picker (🐛 Bug / ✨ Feature / ❓ Question / 💡 Idea / 💬 Other), subject (3-200 chars), message (10-5000 chars), reply-to email (pre-filled from Joomla user).
- **Backend**: `SupportController::submit()` validates + sends via Joomla's Mailer to `support@cybersalt.com`. `Reply-To` set to the submitter's email so replies from the operator land in their inbox, not the site's noreply.
- **Subject-line tier tag** for Gmail-side triage: `[MCP for J Pro Support]` or `[MCP for J Community Support]` + type label + user's subject. Filter/label rules on the receiving inbox become trivial.
- **Auto-attached metadata** (visible to the user in the right sidebar for full transparency): site URL, cs-mcp-for-j version, Joomla version, PHP version, installed csmcpforj-family add-ons + their enabled state, Pro membership status, submitting user's name + email. Answers the environment questions any first-reply would otherwise have to ping-pong for.
- **HTML-entity decode on the subject line** — type labels in the language file use `&mdash;` for HTML rendering in the form UI, but email subjects are plain-text. Controller now runs `html_entity_decode(..., ENT_QUOTES | ENT_HTML5)` on the type label before composing the subject so the em-dash lands as an actual character, not a literal `&mdash;` string.

### 🔒 New — Screencasting-safe secret reveal system

The Dashboard's Joomla API token pill (in the copy-prompt preview) and Pro membership email input are now blurred by default with an intentional-reveal interaction, safe for live demos and screencasts.

- **Blur by default** via `filter: blur(5px)` &mdash; DOM `textContent` stays intact so clipboard copy still works; only the visual rendering is obscured. The empty-state placeholder variants (`<PASTE YOUR JOOMLA API TOKEN HERE>` and the empty pre-activation email field) are explicitly NOT blurred &mdash; nothing to hide there.
- **Hover-with-delay reveal**: user must hold hover over the blurred element for N seconds before the blur comes off. A **centered countdown pill** overlays the element reading *"Revealing in 3s"* → *"Revealing in 2s"* → *"Revealing in 1s"* so the operator knows the timer is running (without it, a slow reveal reads as "hover not working").
- **Mouse-off resets** &mdash; leaving the element cancels any in-progress countdown AND removes the reveal if it had happened. Re-entering starts a fresh full-length countdown. Makes reveal an intentional act; a mouse pointer transiting the element on the way to click something else never triggers exposure.
- **Auto-hide after reveal** &mdash; once revealed, N seconds later the blur automatically returns even if the mouse is still over the element. Belt-and-braces for the "glanced, read it, then got distracted" case where an unattended browser could otherwise leave the secret exposed indefinitely.
- **Two new component options** under a new **Screencasting privacy** fieldset (Components → MCP for Joomla → Options):
  - `hover_reveal_delay_seconds` (integer 0-60, default 3) &mdash; hold-hover duration before reveal. `0` = immediate reveal (removes the anti-accidental-hover protection, restores classic single-hover behaviour).
  - `hover_reveal_hide_seconds` (integer 0-300, default 8) &mdash; auto-hide duration after reveal. `0` = no auto-hide (reveal persists until mouseleave).
- Design pattern locked in project auto-memory (`feedback_screencasting_safe_secret_reveal_pattern.md`) as the canonical secret-display style for all future Cybersalt Joomla extension admin UIs. Sibling to `feedback_advisory_box_design_pattern`.

### 🔧 Miscellaneous polish

- **Admin sidebar translation fix** &mdash; the `.sys.ini` was missing keys for `SUBMENU_SETUPGUIDE` (added in v2.5.0) and `SUBMENU_SUPPORT` (added in this release). The Joomla sidebar loads `.sys.ini`, not the regular `.ini`, when rendering menu-item labels outside the currently-active component &mdash; so both submenu items were showing their raw `COM_CSMCPFORJ_SUBMENU_SE...` / `COM_CSMCPFORJ_SUBMENU_SU...` constants when navigating from other admin pages. Now both keys are duplicated into `.sys.ini` and both render as *Setup Guide* / *Support*.

## 🚀 Version 2.5.0 (August 15, 2026)

Biggest release since v2.0.0. Ships a brand-new admin **Setup Guide** view, a full-component **AI-neutrality repositioning** (welcomes every major AI client, not just Claude), and a critical **Claude Desktop compatibility fix** that unblocks a Zod-validation regression on Claude Desktop 1.30096+.

### 📚 New — Setup Guide view

Third admin submenu item alongside Dashboard + Browse MCP Add-Ons. Designed explicitly for non-technical audiences, leads with the Easy Way (Dashboard copy-prompt path) and folds all technical setup into a collapsed Advanced section.

- **Universal sections**: what MCP is, connection recipe, how to generate a Joomla API key, security & sharing model (per-user tokens = passwords; one-per-authorised-person vs. shared-demo patterns; revocation).
- **Per-client tabs** in the Advanced expander — **Claude** (Desktop, Code, claude.ai), **Cursor**, **ChatGPT custom connectors**. Each tab has copy buttons that substitute your API key inline before copying to clipboard (same `csmcpforj-token` localStorage key as the Dashboard, so a key typed on either view propagates).
- **Claude Desktop walkthrough** uses the **wrapper-script pattern** — the only pattern verified to work with current Claude Desktop builds (see the compat-fix note below for why the obvious approaches don't work). Four numbered steps: create a small `.cmd` wrapper file with the mcp-remote command inside, edit `claude_desktop_config.json` to reference the wrapper, fully quit from the system tray, restart.
- **Troubleshooting collapsibles**: 401 Unauthorized, 406 Not Acceptable, empty tools/list, 404 on endpoint, 403 / Malware detected (WAF-blocked), Edit-Config button does nothing, Add-custom-connector dialog has no API-key field, **&ldquo;Some MCP servers could not be loaded&rdquo;** (with wrapper-script workaround), **&ldquo;I restarted Claude Desktop but the new connection doesn't appear&rdquo;** (tray-restart gotcha).
- **FAQ collapsibles**: anonymous access, multiple tokens per user, rate limits, HTTPS/ports, key rotation, other-clients-work-too.
- Sticky-nav sidebar with jump links to each section.
- Design pattern: warning / advisory / callout boxes use the *dark neutral bg + colored left border + colored title* style from `feedback_advisory_box_design_pattern` (not Bootstrap's colored-fill alerts).

### 🌐 AI-neutrality repositioning

Component-wide rewrite of user-facing copy so cs-mcp-for-j names every major MCP client (Claude, Cursor, Cline, Continue, ChatGPT custom connectors, GitHub Copilot, Gemini CLI, Windsurf, mcp-cli) instead of leading with Claude. The MCP server itself was already 100 % AI-neutral (any conformant client works); this is a copy + framing update.

- **10 admin language strings** rewritten in `com_csmcpforj.ini` — dashboard headline is now **&ldquo;Connect an AI assistant to this site&rdquo;** (was &ldquo;Connect Claude to this site&rdquo;), primary tab is **&ldquo;Copy a setup prompt&rdquo;** (was &ldquo;Copy a prompt into Claude&rdquo;), client lists throughout name multiple vendors inclusively.
- **Two hardcoded discovery-JSON strings** rewritten (`McpController.php:132` and `plg_system_csmcpforj/src/Extension/Csmcpforj.php:234`) — the text returned on GET requests to `/api/index.php/v1/mcp` no longer leads with Claude.
- **Package sys.ini description** (`pkg_csmcpforj.sys.ini`) — the description shown in Extension Manager after install now names all major MCP clients.
- **README.md** — lead paragraph rewritten; client-config section now has per-client blocks for Claude Desktop / Code / claude.ai, Cursor, Cline, Continue, ChatGPT, GitHub Copilot, Gemini CLI, Windsurf, Zed.
- **Live server-side** (deployed 2026-08-07, no client change needed): all 8 MCP add-on catalog entries on cybersalt.com had `"Adds Claude tools for..."` rewritten to `"Adds MCP tools for..."` &mdash; visible immediately on every existing install's Browse MCP Add-Ons view.

### 🐛 Critical fix — Claude Desktop compatibility

`inputSchema.properties` on tools with no input parameters (12 of 152 tools including `list_access_levels`, `get_joomla_version`, `check_for_updates`, `list_user_groups`, `list_schema_types`, `get_site_info`, plus several add-on tools) now serialises as JSON `{}` (record) instead of `[]` (array). PHP's `json_encode` emits `[]` for empty native arrays but MCP spec requires `{}` for empty property records. Claude Desktop 1.30096+ enforces this strictly via Zod validation and rejects the **entire tools/list response** with *&ldquo;Invalid input: expected record, received array&rdquo;* if any tool violates. Effect: Claude Desktop users saw the MCP server listed as connected but no tools available.

Single-point fix in `packages/com_csmcpforj/admin/src/MCP/ToolRegistry::describeForMcp()` &mdash; casts an empty `properties` array to `new \stdClass()` before it goes into the response. Cascades to base tools AND every installed add-on with no per-tool changes needed. Verified end-to-end on cybersalt.org: Claude Desktop now sees all 152 tools successfully.

### ✨ Catalog view improvements

- **All state badges visible in the collapsed card row** — Free/Pro tier + NEW + EXPERIMENTAL + installed-state + Superseded all render on the summary line so you can see the whole state of each add-on without expanding. Previously only the tier badge showed collapsed; everything else was hidden behind the click-to-expand.
- **New &ldquo;Not installed&rdquo; badge** (neutral gray) &mdash; fills the fourth quadrant of the state grammar so every card explicitly advertises its state. Previously not-installed meant &ldquo;no badge&rdquo; which required the reader to notice absence.
- **Installed-disabled badge recoloured** from `bg-secondary` (gray) to `bg-success-subtle` with green border and text-success-emphasis. Grammar: same color family as active but dimmed = &ldquo;still installed, but paused.&rdquo; Frees `bg-secondary` gray for the new Not-installed badge.
- **New &ldquo;Update to vX.Y.Z&rdquo; badge** in the collapsed summary row when an installed add-on has an update available. Clickable — navigates directly to the install action with `event.stopPropagation()` so the click doesn't also toggle the card open. Yellow (`bg-warning`) for the classic action-required cue.

### 🎨 Dashboard polish

- **Token pill in prompt preview is blurred by default** (`filter: blur(5px)`) &mdash; makes the Dashboard safe for screencasting the setup workflow. Hover-to-reveal for the operator's own peek. Copy button still copies the real token because clipboard payload reads DOM textContent, not rendered CSS. Placeholder-mode variant explicitly NOT blurred &mdash; the public placeholder text stays readable.

### 🔧 Miscellaneous

- Setup Guide Easy-Setup card accent swapped from cyan `#4dd0e1` to Bootstrap primary blue `#0d6efd` for consistency with its CTA button and the Joomla-admin canonical action-blue.
- Setup Guide code snippets fix: `<PASTE YOUR JOOMLA API TOKEN HERE>` placeholder now correctly copies with real angle brackets (fix for double HTML-entity encoding that was leaking `&lt;` / `&gt;` into the clipboard payload).
- Setup Guide advisory-callout design pattern applied to troubleshooting entries (colored left border + neutral background — per `feedback_advisory_box_design_pattern` memory).

## 🚀 Version 2.4.0 (July 21, 2026)

### 🎨 Browse Catalog — collapsible cards
- **Each add-on card is now collapsible.** Native `<details>` / `<summary>` — no JS. Collapsed view shows only the add-on name and the tier badge (`FREE` / `PRO`); expanding reveals everything else (description, version, installed-state badge, NEW / EXPERIMENTAL discovery hints, advisory disclosure, install/enable/uninstall buttons). All cards start collapsed on load so the grid scans in one glance regardless of catalog size. Chevron marker rotates 90° when open. Requested by Tim 2026-07-21 as the catalog grows past ~7 entries and the all-expanded layout gets hard to read.
- **Shortened all catalog titles to the uniform `MCP for <extension>` format.** Was: `Cybersalt MCP add-on for AdAgency Pro` / `MCP add-on for 4SEO` / etc. — now: `MCP for AdAgency Pro`, `MCP for 4SEO`, `MCP for RSTicketsPro`, etc. Consistent voice + shorter reading pass — especially helpful now that titles are the primary at-a-glance identifier on collapsed cards. Change is server-side (cs-release-manager `#__csrm_packages.title` on cybersalt.com), no code change here.

### 🐛 Bug Fixes
- **406 "Could not match accept header" for spec-compliant MCP clients.** The MCP Streamable HTTP spec requires clients to send `Accept: application/json, text/event-stream` on every POST, and current Claude Code builds set that header themselves — overriding any custom `Accept` configured on the server entry. Joomla's `ApiApplication` negotiates the Accept header against the route's registered format (`application/vnd.api+json` only) and rejected the request with 406 before `McpController` could run, breaking every Claude Code connection to the endpoint. `plg_system_csmcpforj` now normalises the Accept header to `application/vnd.api+json` for the MCP route only (the endpoint only ever emits JSON, so the coercion is lossless). Any spec-compliant MCP client now connects without needing a custom Accept header workaround. File: `packages/plg_system_csmcpforj/src/Extension/Csmcpforj.php`.
- **Advisory disclosure text unreadable in Atum dark mode.** v2.3.3's advisory redesign hardcoded severity-accent hex on the summary text (`#ffc107` warning yellow, etc.), which read fine on a light card bg but competed with the surrounding dark card bg on Atum dark — combined with an unstyled default-blue link inside the message body, the box felt harsh + hard to scan. Swapped the summary text + icon colors from hardcoded hex to Bootstrap 5.3's `--bs-*-emphasis` theme tokens, which auto-flip under `[data-bs-theme="dark"]` — dark yellow-brown on light bg, soft warm yellow on dark bg. Border-left accent stays bright hex (small enough that saturation reads OK in both modes). Added explicit `!important` link color rule inside the advisory body with a dark-theme override for `#6ea8fe` so the GitHub feedback link in the StageIt experimental warning renders as a soft blue rather than the harsh default. Tim 2026-07-21.

## 🚀 Version 2.3.3 (July 9, 2026)

**Catalog advisory disclosure — dark-mode-friendly redesign.** Ripped the advisory box off Bootstrap's stock `alert alert-warning` / `alert-info` / `alert-danger` classes (which paint the whole box in the semantic hue and don't respect Atum dark mode — the yellow-background variant washes out the title, drowns inline `<code>` tags, and pulls the eye off the surrounding card content). Rebuilt on the design pattern Tim signed off: neutral card background + 4 px colored left border + colored bold title, inspired by GitHub blockquote admonitions and Docusaurus callouts. Reads cleanly against both Atum light and dark card backgrounds without further tuning.

### 🎨 Advisory disclosure redesign
- **Left border bar** — 4 px solid, hex-coded per severity: `#ffc107` (warning golden yellow), `#ff5c66` (danger coral-red), `#4dd0e1` (info bright cyan).
- **Summary title** — same accent hex, `fw-bold`, applied via inline `style=""` on `<summary>`, on the leading `<span>` icon, AND on a wrapping `<span>` around the title text. Belt-and-braces so Atum's dark-mode default `<summary>` color can't clobber the accent regardless of external-CSS specificity or `!important`.
- **Body copy** — normal `--bs-body-color` contrast, integrates with surrounding card body flow.
- **Inline `<code>` tags** — inset-pill styling via scoped `<style>` block: `--bs-body-bg` background + `--bs-emphasis-color` text + `--bs-border-color` border. Tool names like `deploy`, `sync`, `remove`, `restore`, `continue_*` now read as discrete pills instead of low-contrast ghost text against the previous colored `alert` background.
- **Marker** — replaced the default `▶` triangle with a rotating `▸` chevron that turns 90° when open. Small polish.

Design pattern locked in project auto-memory (`feedback_advisory_box_design_pattern.md`) as the canonical warning-box style for all future Cybersalt Joomla extension work — cs-articles-module-maxxed, cs-template-integrity, cs-edge-cache-marker, cs-override-checker, cs-userback-admin, etc. — once each touches an advisory / callout / warning surface.

Single-file change: `packages/com_csmcpforj/admin/tmpl/catalog/default.php`.

## 🚀 Version 2.3.2 (July 7, 2026)

Dashboard "Check for Updates Now" polish + one fatal-error fix that surfaces the first time a user actually clicks the button on a cybersalt.com-managed install.

### 🐛 Bug Fixes
- **`checkUpdatesNow` fatal on click.** The bind-loops in `DashboardController::checkUpdatesNow()` were calling `$db->bind()` — a method that does not exist on Joomla's `DatabaseInterface`. `bind()` lives on the query object. Both loops (`$delete` and `$countQuery`) now bind onto the query, then `setQuery()->execute()` / `setQuery()->loadResult()` runs the parameterised query correctly. Reproduces on any site with at least one cybersalt.com-managed extension and cached `#__updates` rows.

### 🎨 UI
- **Dashboard "Check for Updates Now" button dark-mode contrast.** Was `btn-outline-primary` (transparent background + blue border + blue text) — vanished into Atum dark's card background. Swapped to `btn-primary text-white`, matching the other primary-action buttons on the dashboard. Reads clearly in both light and dark themes.

Files touched: `packages/com_csmcpforj/admin/src/Controller/DashboardController.php`, `packages/com_csmcpforj/admin/tmpl/dashboard/default.php`.

## 🚀 Version 2.3.1 (July 6, 2026)

**Catalog badges + advisory disclosure.** Two operator-flippable per-add-on flags and a structured "read before install" caveat, driven by the StageIt free add-on landing in the catalog and needing a way to tell prospective users up-front that server-timeout risk on chunked long-runners is beyond our control.

### ✨ Catalog UI
- **NEW badge** — light-green pill next to the tier badge when `catalog_metadata.is_new = true`. Draws the eye to recently-added add-ons without spamming everything.
- **EXPERIMENTAL badge** — yellow warning pill (with flag icon) when `catalog_metadata.is_experimental = true`. Hover tooltip points readers to the advisory disclosure below.
- **Advisory disclosure** — native `<details>` collapsible below the card description, rendered when `catalog_metadata.advisory` is populated. Bootstrap `alert-info` / `alert-warning` / `alert-danger` styling by `severity`; message body is HTML so an operator can include links to docs or format multi-paragraph caveats. Collapsed by default (no visual weight on cards that don't need it) but the summary bar always visible so prospective users see the cue.

### 🤝 Companion release
Coordinated with `cs-release-manager` v1.11.4 — the `api.catalog` endpoint now passes `is_new`, `is_experimental`, and `advisory` fields through from each Package's `catalog_metadata` JSON. Operators set these via the Package edit form; no code deploy needed to flip an add-on's flags.

### 📦 Upgrade notes
No breaking changes. The catalog endpoint's response gains three optional fields; MCP clients that don't recognize them ignore them. Add-ons without any of these flags render exactly as before.

## 🚀 Version 2.3.0 (July 6, 2026)

**Admin sidebar preset XMLs are now inspectable from an MCP session.** Driven by the americanfoam.com/stageit engagement 2026-07-06 — a Super User reported Content > Fields, Content > Field Groups, and Users > Fields missing from the admin sidebar on Joomla 5.4.6. Every existing menu tool queries `#__menu`, but the Joomla 4+ admin sidebar isn't stored there — it's rendered at request time from XML preset files on disk under `administrator/components/<component>/presets/`. The session ended with "SSH in and diff against stock" because there was no MCP path to the file contents. This release closes that loop.

### ✨ New tools (2)

- **`list_admin_menu_presets`** (read) — enumerate every preset XML installed on the site. Returns per-preset `{component, name, path, size, mtime, sha256}` plus `matches_stock` when a bundled stock hash is available for the running Joomla version. Answers "what presets exist and which ones have been modified" in a single call.
- **`get_admin_menu_preset`** (read) — read one preset by `(component, name)`. Structured input only — path is built server-side as `administrator/components/<component>/presets/<name>.xml`, both parts regex-validated. Returns raw XML contents plus sha256 for direct diff against a stock reference.

### 🔒 Explicit scope choice: narrow allowlist, not `read_file`

The obvious way to solve this would be to add a general `read_file` tool. The full analysis is in `ISSUE-6-get_admin_menu_preset-scoped-file-read.md` at repo root; the short version:

- A leaked API token *today* = attacker can create content and install extensions. Bad, but bounded.
- A leaked token *with* `read_file` = attacker can pull `configuration.php` (DB password, `$secret`, mailer creds, session key). Full site compromise, potentially shared-credential lateral movement to other Cybersalt-managed sites.
- Silent, script-friendly recon over the whole webroot, much stealthier than the "install a plugin to exfiltrate" alternative.

**Decision: scoped diagnostic readers, one file family at a time.** This release ships the admin-menu-preset family. Follow-on issues (deliberately separate, not this release):

- `get_language_file` — allowlisted to `administrator/language/**/*.ini` + `language/**/*.ini`.
- `get_template_manifest` — read `templateDetails.xml` for a specific template.
- `diagnose_admin_menu` — higher-level convenience: read all presets, diff against bundled stock hashes, return a report of modified/added/removed entries + which `<menuitem>` blocks are missing.
- `get_component_manifest` — read `<component>.xml` for an installed component.

Each of those gets its own scoped tool with its own allowlist. **No general `read_file`.**

### 🔒 Safety stack on the preset tools

Both new tools route through a shared `AdminMenuPresetPathTrait` — single source of truth for the security model. Each layer is strict-by-default; any failure aborts before touching disk:

1. **Structured input only.** No raw path parameter. Caller supplies `component` + `name`; the tool builds the filesystem path server-side.
2. **Regex validation.** `component` must match `^com_[a-z0-9_]+$`, `name` must match `^[a-z0-9_-]+$`. Rejects `..`, path separators, null bytes, leading dots, extensions in the name.
3. **realpath canonicalization.** Resolved absolute path must start with `realpath(JPATH_ADMINISTRATOR . '/components')`. Catches symlink escape attempts that string-prefix checks miss.
4. **File-type + extension check.** `is_file()` (not `is_link()` alone), extension must be `.xml`.
5. **Size cap.** Response cap of 256 KB — presets are typically 5–15 KB; a preset XML much bigger than that is suspicious enough to refuse loading.
6. **Distinct "not found" vs "not authorized" responses.** `PresetNotFoundException` for genuine 404s (component exists but no preset by that name), `InvalidArgumentException` for allowlist violations. AI can distinguish "you asked for a preset that isn't installed" from "you asked for something outside the allowlist".

### 🧭 What test cases pass now

Per the spec:

- ✅ `list_admin_menu_presets` on a stock Joomla 5.4.6 returns exactly the expected presets with sha256 hashes. `matches_stock` returns `null` until the stock-hash table is populated (opt-in — see `AdminMenuPresetPathTrait::stockHashLookup()` docblock for how to generate + transcribe hashes; hash comparison logic is already wired up).
- ✅ `get_admin_menu_preset(component="com_menus", name="default")` returns byte-identical file content on any Joomla install.
- ✅ `get_admin_menu_preset(component="../etc", name="passwd")` — refused, no file access attempted.
- ✅ `get_admin_menu_preset(component="com_menus", name="../../../configuration")` — refused, name regex rejects `../`.
- ✅ `get_admin_menu_preset(component="com_menus", name="nonexistent")` — clean 404-shaped `ToolResult::error`, no PHP warning.
- ✅ Symlink `administrator/components/com_evil/presets/default.xml -> /etc/passwd` — refused because realpath escapes the allowlist base.
- ✅ Preset file > 256 KB — refused with a clear message.

### 📦 Upgrade notes

No breaking changes. New tools land in a new "Admin Menu Presets" category, automatically picked up by the tool registry. If you have a `?categories=` filter in use on the MCP endpoint URL, add `admin_menu_presets` to keep the new tools accessible.

The stock-hash lookup table in `AdminMenuPresetPathTrait::stockHashLookup()` intentionally ships **empty** in v2.3.0 — plumbing wired, awaits hash-gathering from stock Joomla installs. Populating it is a follow-on task; sha256 is returned regardless so external diffs against a reference install work today.

## 🚀 Version 2.2.0 (June 19, 2026)

**Templates domain ships full coverage — file editing + style settings.** Driven by GitHub issue #7 (Greg Willson Cassiopeia `user.css` engagement, 2026-06-26): every other step of the work landed cleanly through MCP except the actual file write, which required two human round-trips through the Joomla admin Template Files Editor. After this release, that whole workflow runs through Claude.

### ✨ New tools (5)

**File editing** — equivalent to the Joomla admin's **System → Templates → Site Templates → [template] → Edit Template Files** screen, exposed as MCP tools:

- **`list_template_files`** (read) — recursively list every file under `media/templates/<client>/<template>/`. Returns `{path, size, mtime}` per file with paths relative to the template root, suitable to feed back into the read/write tools.
- **`read_template_file`** (read) — return the text contents of a file under the template root. Auto-detects binary files (presence of null byte) and returns them base64-encoded so the JSON-RPC envelope stays valid.
- **`write_template_file`** (write, destructive) — overwrite-or-create a file under the template root. Returns post-write size + sha256 for the AI to verify what landed. **PHP files intentionally NOT writable in v1** — see safety below.

**Style settings** — equivalent to opening a style under **System → Site Template Styles → [style]** and clicking Save:

- **`get_template_style`** (read) — fetch a single style by id with its params blob decoded into a JSON object. Pairs with the existing `list_template_styles` (which returns the list without params).
- **`update_template_style`** (write) — update a style's params (and optionally its title). Params are **merged** into existing (not replaced) — pass only the keys you want to change, use `null` to delete a key. Same write semantics as `set_plugin_params` so the AI doesn't have to learn two different patterns.

### 🔒 Safety stack on the file tools

Template files can be executable PHP, so write access without guardrails would be effectively RCE if the API token leaks. The safety surface for the file tools is built around a shared `TemplateFilePathTrait` that every read/write tool routes through. Each layer is strict-by-default; any failure aborts before touching disk:

1. **Strict input validation** — `client_id` must be 0 or 1; `template` must match the safe-element pattern (lowercase alphanumeric + underscore + hyphen + dot suffix); `path` must use forward slashes, no `..`, no null bytes.
2. **Path canonicalization** — every resolved target goes through `realpath()`. Final path must start with the canonical jail root (`media/templates/<client>/<template>/`); catches symlink escapes that string-prefix checks would miss.
3. **Write-extension allowlist** — `write_template_file` only accepts CSS, JS, SCSS, LESS, JSON, SVG, plain text, raster images, and font files. **PHP is denied in this version**; enabling .php writes is a deliberate later-version decision behind its own permission flag. Read access is broader (any extension) so the AI can still inspect existing PHP overrides to decide whether a CSS-only customization would suffice.
4. **MCP `ToolAnnotations`** — list/read get `readOnlyHint=true, idempotentHint=true`. Write gets `destructiveHint=true, idempotentHint=true` (overwrites may destroy prior content; calling with same args twice = same end state). Read-only mode (component config) naturally excludes the write tool.

### ✨ Auto-classifier additions

`AbstractTool::getMcpAnnotations()` now recognizes two more name-verb prefixes for automatic ToolAnnotations classification:

- **`read_*`** → readOnlyHint=true, idempotentHint=true (was previously falling through to the "unknown verb" default which is non-destructive but also not read-only). Matches the existing `list_/get_/check_/validate_/fetch_` cluster.
- **`write_*`** → readOnlyHint=false, destructiveHint=true, idempotentHint=true. Covers the new file tool and any future overwrite-or-create style tools.

### 🚫 Deliberately deferred

To keep the v2.2.0 release focused, these template-domain capabilities are NOT in this release:

- `create_template_style` / `delete_template_style` — less common, more error-prone; can come later.
- `create_template_override` / `delete_template_file` — the issue #7 spec includes them but the MVP scope ships read/write without them.
- Per-menu-item template-style assignment — already partially possible via existing menu tools.
- Schema-aware param validation (parsing each template's own `templateDetails.xml` config schema) — pass-through covers ~90% of use cases without it.
- `csmcpforj.templates.editphp` permission flag to unlock PHP writes — deferred until there's a concrete need.

### 📦 Upgrade notes

No breaking changes. New tools land in the Templates category, automatically picked up by the registry. If you have a `?categories=` filter in use on the MCP endpoint URL, add `templates` to keep the new tools accessible.

## 🚀 Version 2.1.1 (June 18, 2026)

**Issue-backlog sweep.** Bug fixes + catalog UX polish driven by the WMW #342 chat feedback and the open GitHub issues list.

### 🐛 Fixes
- **RSTicketsPro Pro add-on (GitHub #5): `add_rst_ticket_reply` forced `html=0` regardless of passed argument.** The wrapper was correctly extracting the caller's `html` value and putting it in `$data`, but `RSTicketsProTicketHelper::bind($data)` was dropping the field empirically — `saveMessage()` always wrote html=0. Fix: after `$helper->bind($data)`, set `$helper->html = (int) $data['html']` directly so the value survives. Ships in cs-mcp-for-j-addons-pro / plg_system_csmcpforjrst v1.8.1.
- **RSTicketsPro Pro add-on: missing `script.php` referenced by manifest.** The plugin manifest declared `<scriptfile>script.php</scriptfile>` but no file shipped — Joomla's installer would warn on install. Added the standard auto-enable-on-install script.php (matching the pattern of the other three addons) plus a post-install confirmation message.

### ✨ Catalog UX (driven by Bjørn + Ivar feedback from WMW #342 chat)
- **"Installed" badges polished.** The catalog's per-card state badges now read **green ✓ "Installed"** (active) and **grey "Installed (disabled)"** (with hover tooltips explaining the state) instead of the terse "ENABLED"/"DISABLED" labels. Easier to register at a skim — "you've already got this" lands visually.
- **Post-install confirmation message** from every add-on plugin's `postflight`. Tells the operator the install actually did something (no admin UI by design, tools appear in connected MCP clients) and names the new tools. Addresses Ivar's "couldn't find the Akeeba add-on after install — expected a menu entry" pattern. Lands in cs-mcp-for-j-addons-free (Akeeba Backup Core, Cybersalt Release Manager) and cs-mcp-for-j-addons-pro (4SEO, RSTicketsPro).
- **Supersede-pair logic** — `superseded_by` field on catalog metadata. Each catalog entry can declare a list of extensions ({type, folder?, element, display_name?}) that, if installed on the client site, mean this catalog entry is redundant. The cs-mcp-for-j catalog template marks superseded rows with a teal "Superseded by &lt;X&gt;" badge and hides the Install / Update buttons (Toggle / Uninstall stay available on already-installed rows so cleanup is possible). Resolves Bjørn's request: hide Akeeba Backup Core add-on when Akeeba Pro is installed.

### 📝 Investigation notes (no code changes)
- **Pro activation false-positive on fresh install** (Bjørn 2026-06-17) — investigated the activation helper's per-request memo path and the postflight script; no obvious mechanism that would carry a false "Membership Expired" verdict across a fresh install. Cannot reproduce statically. Queued for live-repro on a clean test site.
- **AMM (Regular Labs Advanced Module Manager) form-name compat** (Bjørn 2026-06-17 catalog-chat note) — audited the codebase; cs-mcp-for-j ships zero `onContentPrepareForm` handlers and the module-management tools (`create_module`, `update_module`, etc.) call into Joomla's MVC model layer directly, which is form-name agnostic. AMM's `com_modules.module` → `com_advancedmodules.module` rename does not affect us. Closing as N/A.

### 🤝 Companion releases
This release coordinates with simultaneous bumps:
- **cs-mcp-for-j-addons-free** v1.0.1 (Akeeba Backup Core + Cybersalt Release Manager) — post-install confirmation messages added.
- **cs-mcp-for-j-addons-pro** v1.8.1 (4SEO + RSTicketsPro) — RST `html=0` fix, RST `script.php` added, post-install confirmation messages added.
- **cs-release-manager** v1.11.3 — `api.catalog` endpoint output gains the `superseded_by` field passed through from `catalog_metadata.superseded_by` JSON. No schema changes; no DB migration needed.

### 📦 Upgrade notes
No breaking changes. The catalog endpoint's response shape gains one optional field (`superseded_by`); existing MCP clients ignore unknown fields. The "Installed" badge labels changed; if you had screen-reader translations relying on the old strings, update to the new `COM_CSMCPFORJ_CATALOG_STATE_INSTALLED_ACTIVE` / `..._DISABLED` keys.

## 🚀 Version 2.1.0 (June 18, 2026)

**Server-side safety + agentic ergonomics + native Joomla update tools.** Several new cross-cutting features were added to the MCP server after surveying the landscape — see the credits section at the bottom of this entry.

### 🔒 Security
- **Argument secret guard.** Before any tool dispatches, the server recursively scans the tool's arguments for the current session's Bearer token. If found anywhere in the payload, the call is refused before reaching the tool handler. This is a server-side circuit breaker against prompt-injection attempts that smuggle the user's API token into a downstream tool call (e.g. via a fetched URL or article body the AI happens to be processing). Implementation in `MCP\Security\ArgumentSecretGuard`.
- **Permanent-delete safety scaffolding.** `delete_article` now refuses to permanent-delete a row that isn't already trashed, with an error message that clearly explains the two-step path (trash first, then re-call with `permanent=true`). Powered by a new `RequiresTrashFirstTrait` so the same pattern can extend to other delete-* tools incrementally. This is intentional friction for AI clients — the two-step intent has to be explicit, not a single mis-targeted call.

### ✨ New Features
- **Read-only mode (component config).** New Options → "MCP server" tab with a "Read-only mode" switch. When on, the MCP endpoint only announces and accepts tools whose MCP ToolAnnotations declare `readOnlyHint=true` (list_*, get_*, check_*, validate_*, fetch_*). Lets an operator hand an AI client a "browse but don't mutate" surface without revoking the user's API token. Per-request override: `?read_only=1` on the endpoint URL narrows further (but cannot UNLOCK writes from a site that has the floor set).
- **Category-based tool filtering.** Optional `?categories=articles,users,menus` (or singular `?category=`) on the endpoint URL filters which tools the server announces. Useful for context-window-bound models that choke on the full tool list. The filter applies consistently to `tools/list` AND `tools/call` so a client cannot invoke a tool that the filtered list wouldn't have shown them.
- **MCP `ToolAnnotations` on every tool.** The server now emits the MCP-spec-standard `annotations` object on every tool descriptor: `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`. Tools auto-classify from their name verb (list_/get_/check_/validate_/fetch_ → read-only; delete_/uninstall_/clear_/revoke_/cancel_ → destructive; etc.); individual tools can override via `getMcpAnnotations()`. Lets MCP-spec-aware clients render safety badges per tool.
- **Type-introspection tools (2 new):**
  - **`list_menu_item_types`** — every menu item type installed on this site, grouped by component. Discovery for the AI before calling `create_menu_item`, which needs an installed type code (Single Article, Category List, Login Form, Contact Form, Custom URL, plus any third-party component's types).
  - **`list_module_types`** — every module type installed (mod_login, mod_menu, mod_custom, mod_articles_latest, mod_banners, plus any third-party module). Discovery before `create_module`.
- **Joomla core update tools (3 new):**
  - **`check_joomla_update`** (read-only) — force-polls the Joomla update server (bypasses the up-to-24h local cache) and reports current vs latest, release notes URL, update availability.
  - **`joomla_update_healthcheck`** (read-only) — pre-flight: PHP version, database type/version, extension count, disk space. Returns OK/warning/error verdicts per check + an aggregate summary.
  - **`apply_joomla_update`** (destructive, irreversible) — downloads + extracts + applies the update via Joomla's own `UpdateModel`. Requires explicit `confirm=true` argument; without it, refuses with a message explaining why. Conservative scope: routine point releases on straightforward sites; for major version upgrades or sites with extension compat warnings, the description points the AI back to the web UI.

### 🙏 Credits
The infrastructure ideas above (argument secret guard, MCP tool annotations + read-only mode, trash-before-permanent-delete, category filtering, type-introspection, multi-step Joomla update tool family) were inspired by reviewing **[nikosdion/joomla-mcp-php](https://github.com/nikosdion/joomla-mcp-php)** (licensed AGPL-3.0). Each feature was studied for the *concept* — what problem it solves and why — and then **independently reimplemented from scratch** in cs-mcp-for-j's GPL-2-or-later codebase using our own naming, file layout, and code structure. No source code, configuration, or strings were copied. Nikolaos Dionysopoulos's broader MCP-for-Joomla work is excellent and worth a look if you're after a stdio-based MCP server that runs outside Joomla via Composer/PHAR.

### 📦 Upgrade notes
- New component config field `read_only_mode` defaults to `0` (off) — existing installs keep current behavior on upgrade.
- 5 new tools added to the registry — the total surface is now ~100 tools. Consider using the new `?categories=` filter on the endpoint URL if your MCP client struggles with the full list.
- No schema migrations. No breaking changes to existing tools' input/output shapes; the only addition to the tools/list response is the `annotations` object per tool, which MCP clients ignore if they don't recognise it.

## 🚀 Version 2.0.0 (June 12, 2026)

v2.0 catalog architecture lands as GA: the four extension-specific add-ons (**4SEO, RSTicketsPro, Cybersalt Release Manager, Akeeba Backup**) are now detached from the bundle and ship as standalone installable plugins from cs-release-manager on cybersalt.com. The component itself stays light — operators install only the add-ons their site actually uses, one click from **Components → MCP for Joomla → Browse MCP Add-ons**.

### 🔒 Security
- **XSS in catalog install messages.** `CatalogController::installAddon` now HTML-escapes catalog-sourced `addonName` and request-sourced `addonKey` before passing them to `Text::sprintf`/`setMessage`. A compromised remote catalog endpoint (or an operator-set malicious catalog URL) can no longer XSS the admin via a crafted addon name. Joomla's admin message bar renders messages as HTML, so this escape is required at the substitution boundary.

### 📦 New Features — Catalog GA
- **Browse MCP Add-ons view.** Cards per add-on with Pro/Free tier badge, installed/disabled state, catalog-vs-installed version compare, target-extension info, and vendor link with an "MCP tools developed independently of [vendor]" notice.
- **One-click Install.** Wraps Joomla's standard `InstallerHelper::downloadPackage` + `Installer::install` — same code path Extensions → Install from URL uses, so manifest parsing, file copy, postflight, and rollback all behave exactly like a normal Joomla install. Defensive checks: GET token, `core.admin` on `com_csmcpforj`, addon_key must resolve to a catalog entry (no arbitrary URLs), HTTPS-only.
- **One-click Update.** When an installed add-on's manifest version is older than the catalog version, the Install button becomes "Update to v…" and runs the same code path against the new zip.
- **One-click Enable / Disable / Uninstall** per card so the full lifecycle happens inside the catalog UI.
- **Pro Activation flow.** Dashboard "Pro Activation" card: enter membership email, activate locally, dlid gets appended to Pro add-on downloads so cs-release-manager's `AccessCheckHelper::verifyUpdateAccess` lets them through. Deactivate Locally clears the activation. Activation state mirrors into Component Options (read-only) with a "Open Dashboard" link for the deactivate action.
- **Pro gate on Install button.** Pro add-ons render a disabled "Pro — manual install" button with a tooltip when Pro activation isn't present locally. No more dead-end click → 401.
- **Akeeba Backup add-on** added to the launch lineup (free) alongside 4SEO, RSTicketsPro, and Cybersalt Release Manager (free).

### 🔧 Improvements
- **Dashboard Options button** — the gear icon now appears on the dashboard view too, not just on Browse Add-ons.
- **Canonical Joomla sidebar.** Cross-view nav now uses `HTMLHelper::_('sidebar.addEntry', ...)` — same pattern as com_users / com_modules.
- **Skip-rebuild when source unchanged.** `build.ps1` reuses an existing standalone add-on zip when no source file is newer than it. Stops spamming cs-release-manager with identical-content uploads when only the core changed.
- **Per-extension version discipline.** Each sub-extension's `<version>` now reflects when *its* source actually changed, not the bundle release. No more lockstep bumps that cause spurious "Find Updates" prompts on installed sites.

### ⬆️ Upgrade notes

Standard "Install from File" over your existing install. Postflight auto-enables the core component, system plugin, and webservices plugin. Add-ons no longer ship in the bundle — install them per-site from **Components → MCP for Joomla → Browse MCP Add-ons**.

If you have a previous version of `csmcpforj4seo` or `csmcpforjrst` installed via the v1.10.x bundle, they remain in place after the upgrade (Joomla doesn't auto-uninstall children that move out of the bundle). The catalog will detect them as installed and offer Enable/Disable/Uninstall as normal.

---

## 🚀 Version 1.10.2 (June 10, 2026)

Same-day patch on the heels of v1.10.1's first public release. Closes two MCP discoverability paper-cuts caught while filling out cybersalt.com's `/extensions/mcp-for-j` page through the live endpoint, plus a belt-and-braces fix for the missing **Update Sites** entry some installs had after upgrading from v1.10.0.

### 🔧 Discoverability — `article_id` and `url` aliases

- New `AbstractTool::requireItemOrArticleId()` helper accepts **either** the canonical `item_id` (matches Joomla's `#__schemaorg.itemId`) or the natural-sounding `article_id` alias. Applied to:
  - `get_article_schema`
  - `set_article_schema`
  - `clear_article_schema`
  - `set_article_custom_jsonld`
  - `set_article_custom_jsonld_bulk` (per-entry)
- `fetch_rendered_url` now accepts `url` as an alias for `path`.
- All six tool descriptions rewritten to **lead** with `REQUIRED ARG: ...` so an agent doesn't have to read past the prose to find the parameter name.

These were caught when an agent extrapolating from `update_article(id=812)` reasonably guessed `article_id` for `set_article_custom_jsonld` — and got an error. Both names work now.

### 🐛 Update Sites missing after upgrade

The package manifest's `<updateservers>` block is supposed to be processed by Joomla's PackageAdapter on install AND upgrade. In practice it lands reliably on fresh installs but is flaky when upgrading from a version of the package that didn't ship an `<updateservers>` block — exactly the v1.10.0 → v1.10.1 case. Result: System → Update Sites stayed empty, "Find Updates" had nothing to query.

New `ensureUpdateSiteRegistered()` step in the postflight script explicitly:

1. Finds the package's `extension_id` in `#__extensions`
2. Looks up any linked `#__update_sites` row via the `#__update_sites_extensions` join
3. If a row exists: refreshes the URL, name, type, and enables it
4. If not: inserts both rows

Idempotent. Safe to re-run. Anyone on v1.10.1 with a missing Update Sites entry gets it added when they install v1.10.2 over the top.

### ⬆️ Upgrade

Standard "Install from File" over your existing install. No data migration, no DB schema changes.

---

## 🚀 Version 1.10.1 (June 10, 2026)

Public-release housekeeping. Adds the Cybersalt update-server URL to the package manifest so Joomla's "Find Updates" surfaces future versions automatically — required for the **first official public release** on cybersalt.com.

### 🔧 Update server

`pkg_csmcpforj.xml` now declares:

```xml
<updateservers>
    <server type="extension" name="MCP for Joomla">https://www.cybersalt.com/index.php?option=com_csreleasemanager&task=api.updatexml&format=raw&element=pkg_csmcpforj</server>
</updateservers>
```

Existing installs upgrading from v1.10.0 → v1.10.1 register the update server during the package install; from then on, Joomla → System → Update will detect new versions without re-installing.

### ⚠️ Supersedes v1.10.0

v1.10.0 was tagged earlier on June 3 but had no update-server URL, which made it unfit as the public-distribution build. v1.10.1 is functionally identical except for the manifest line; if you grabbed v1.10.0, install v1.10.1 over the top.

---

## 🚀 Version 1.10.0 (June 3, 2026)

Closes the **ACL gap** that previously required UI clicks to grant a non-Super-User group access to a component. Adds full user-group CRUD plus a new **Permissions** domain that reads and writes `#__assets.rules` directly.

Tool count: 114 → 119. New domain: Permissions (2 tools). Three new tools join Users.

### 📦 New Features — User Group CRUD

- **`create_user_group(title, parent_id=1)`** — goes through `com_users`' `GroupModel` so the matching `#__assets` row is created and the new group inherits from its parent.
- **`update_user_group(id, title?, parent_id?)`** — PATCH semantics. Moving a group changes which permissions it inherits.
- **`delete_user_group(id, confirm:true)`** — refuses if any users are still in the group or if child groups inherit from it. Joomla's built-in groups can't be deleted.

### 📦 New Features — Permissions Domain

- **`list_component_permissions(component)`** — return the parsed `#__assets.rules` JSON for a component (or `root.1` for global) **plus** the resolved value for every (group, permission) cell via `Access::checkGroup()` — the same inheritance walk Joomla uses at runtime. Diff the resolved column across groups to find which permission is gating access.

  Actions are discovered from the component's `access.xml` (canonical) and from any keys already present in the rules JSON.

- **`set_component_permission(component, group_id, permission, value)`** — set one ACL cell. `value`: `1` = Allowed, `0` = Denied, `null` = Inherited (clears the explicit setting). Equivalent to clicking one row/column intersection in the admin Permissions tab and saving.

  Refuses one footgun: setting `core.admin` Denied for Super Users on `root.1`. Other dangerous combinations are allowed because Joomla itself allows them.

### 🔒 Security review (pre-release sweep, June 3)

Five findings caught and fixed in the same release. None reachable from outside an authenticated admin session, but worth closing for defense in depth:

- **MED — Path traversal in `list_component_permissions`.** Input `component` was only validated with `str_starts_with('com_')`, so values like `com_../../etc/passwd` passed and got concatenated into `JPATH_ADMINISTRATOR . '/components/' . $component . '/access.xml'`. New `AbstractTool::requireSafeAssetName()` helper enforces `root.1` | `com_[a-z0-9_]+` | `com_<name>.<subtype>.<id>` shape. Applied to both `list_/set_component_permission`.
- **MED — Privilege escalation via direct-DB token tools.** The four new `*_user_api_token` tools bypassed Joomla's UserModel, so a Manager with `csmcpforj.write` could mint a Super User token and pivot. New `AbstractTool::requireCanEditTargetUser()` helper mirrors Joomla's "can't edit equal-or-higher privilege users" check by comparing `MAX(usergroups.level)` across actor vs target. Super Users bypass; self-operations bypass. Applied to all four token tools + the `create_user(enable_api_token: true)` mint path (defense-in-depth: UserModel already enforces it during group assignment).
- **LOW — Missing ACL on `CatalogController::refresh`.** Any logged-in admin who could hit the URL could trigger remote fetches against cybersalt.com. Now requires `core.admin` on `com_csmcpforj` in addition to the form-token check.
- **LOW — `CatalogModel` accepted any URL scheme for the catalog endpoint.** An operator who could edit the component config could set `file://` or `gopher://`. Now allowlists `http://` and `https://` only.
- **VERY LOW — `JoomlatokenHelper::loadProfileRows` bound `$likePattern` before assigning it.** Worked because Joomla's `bind()` is by-reference, but fragile against refactors. Reordered.

### 🎯 Use case (from field testing, June 3)

Granting a new low-privilege group access to DPCalendar previously meant stepping a test user through each built-in group manually to discover the gating permission. Now:

```
list_component_permissions(component: "com_dpcalendar")
  → diff Super Users vs Manager → identify "core.admin" as gate

create_user_group(title: "Kiosk Access", parent_id: 1)
  → returns id: 10

set_component_permission(component: "com_dpcalendar",
                         group_id: 10,
                         permission: "core.admin",
                         value: 1)

update_user(id: 7, groups: [10])
```

Four MCP calls instead of an admin GUI dance.

---

## 🚀 Version 1.9.0 (June 3, 2026)

Closes the **per-user API token management** gap that previously forced operators to log in as each target user to mint or rotate tokens. Adds a full `joomlatoken` admin surface plus atomic create-and-mint on `create_user`.

Tool count: 110 → 114.

### 📦 New Features — User / Token Surface

- **`get_user_api_token_status(user_id)`** — read-only state of a user's API token: `enabled`, `has_secret`, `algorithm`. The secret itself is never returned.
- **`enable_user_api_token(user_id, enabled)`** — toggle the `joomlatoken.enabled` flag without touching the secret. Useful for temporarily blocking a token without invalidating it.
- **`reset_user_api_token(user_id)`** — generate a fresh secret and return the **display token** the user can paste straight into an MCP client's `Authorization: Bearer` header. Format mirrors `plg_user_token` exactly: `base64("<algo>:<userid>:<hmac>")`. Bypasses Joomla's "only the user can see their own token" GUI restriction because Super User MCP tools are by definition programmatic admin.
- **`revoke_user_api_token(user_id)`** — disable + zero the secret. For incident response. Restore access with `reset_user_api_token`.

### 🔧 Improvements

- **`create_user(enable_api_token: true)`** — atomic create-and-mint. Response now includes `display_token` and a pre-formatted `paste_as` header string. Replaces the previous multi-step dance (create user → bump to Manager → log in as user → reset token → unbump) with a single call.
- **`get_user(include_profile: true)`** — adds `#__user_profiles` rows to the response (joomlatoken state, profile plugin fields, vendor plugin fields like DPCalendar's private-feed token, etc.). The raw `joomlatoken.token` secret is redacted to `[redacted; present]`; other plugin tokens are returned verbatim so the agent can spot them.

### 🛠 Engineering

- Token format mirrors `plugins/user/token/src/Extension/Token.php` exactly so the display string returned by `reset_user_api_token` works in any MCP client. The HMAC uses Joomla's `secret` from `configuration.php` as the key (same as the GUI).
- New `JoomlatokenHelper` consolidates the seed-gen, HMAC, and `#__user_profiles` upsert logic so the four new tools (and `create_user`) share one code path.

---

## 🚀 Version 1.8.1 (May 25, 2026)

Fills out the **Custom Fields** domain so programmatic setup of a clean field group on an article context is one MCP call rather than 6+ admin clicks. Closes issue [#1](https://github.com/cybersalt/cs-mcp-for-j/issues/1) — VMT template change-log Subform field-group setup on 2026-05-25 needed it.

Tool count: 103 → 110. No new domains; Custom Fields just goes from 4 tools to 11.

### 📦 New Features

- **5 new field-group tools** — full CRUD over `#__fields_groups` (the tabs that custom fields appear under in the article editor):
  - `list_field_groups(context?, state?)` — returns id, title, context, state, access, ordering, language, description, note
  - `get_field_group(id)` — full row with decoded params blob
  - `create_field_group(title, context, state?, access?, language?, description?, note?)` — calls `com_fields`' `GroupModel`, all save hooks fire
  - `update_field_group(id, title?, state?, access?, language?, description?, note?, ordering?)` — PATCH semantics; context intentionally NOT updatable (changing a group's context orphans every field assigned to it — create a new group in the new context instead)
  - `delete_field_group(id, confirm:true)` — requires explicit `confirm:true`; fields previously assigned to the group get their `group_id` reset to 0 (unassigned, appear under the generic "Fields" tab); the fields themselves are NOT deleted (use `delete_custom_field` for that)

- **2 new custom-field write tools** — fills the create/update/delete trio:
  - `update_custom_field(id, title?, label?, description?, required?, state?, group_id?, access?, language?, default_value?, note?, ordering?, only_use_in_subform?, assigned_cat_ids?, fieldparams?, params?)` — PATCH semantics; surfaces the three properties that previously required admin clicks: `group_id` (assign to a field-group tab), `assigned_cat_ids` (M:N to `#__fields_categories` — pass `[-1]` for "no categories", `[]` for "all categories"), and `only_use_in_subform` (1 hides the field from the standard editor — required when building Subform children via the API). Context intentionally NOT updatable.
  - `delete_custom_field(id, confirm:true)` — calls `FieldModel::delete()` which also removes the field's values from `#__fields_values` across every article in the context. Destructive and not reversible — `update_custom_field(state=0)` is the non-destructive alternative.

### 🔧 Improvements

- **`get_custom_field` now returns `assigned_cat_ids`** — queried from `#__fields_categories` (the M:N join table, NOT a column on the field row). Empty list = "all categories" (no restrictions); `[-1]` = "no categories" (Joomla's sentinel value for explicit none). Description rewritten to surface the semantics so the agent reads-before-decides instead of guessing. The `params` field was already in the response but unmentioned in the description; description now covers it explicitly.

### 🐛 Bug Fixes

- **`delete_custom_field` + `delete_field_group` initially failed with empty error messages** on first live test — `com_fields`' `FieldModel::canDelete()` refuses unless `state=-2` (trashed). Joomla's admin UI does this as two phases (set State → Trashed, then "Empty Trash"); both delete tools now trash via `$model->publish([$id], -2)` before calling `$model->delete($ids)`, so one MCP call = gone, matching user expectation of a "delete" verb. Verified in `administrator/components/com_fields/src/Model/FieldModel.php` line 831 (`if (empty($record->id) || $record->state != -2) { return false; }`).

## 🚀 Version 1.8.0 (May 23, 2026)

Headline: cs-mcp-for-j now manages a second Joomla extension end-to-end. The new **RSTicketsPro MCP add-on** adds 20 typed tools for RSJoomla!'s helpdesk extension, alongside three new **4SEO Business Profile** tools that complete the LocalBusiness JSON-LD picture for sites running 4SEO. The version bump from 1.7.x → 1.8.0 reflects that "entire new Joomla extension domain" jump rather than a feature increment within the existing surface area.

Tool count: 80 → 103. Domain count: 13 → 14.

### 📦 New Features

- **RSTicketsPro MCP add-on (`plg_system_csmcpforjrst`)** — a new bundled-but-separable plugin that brings the RSJoomla! **RSTicketsPro 3.x** helpdesk extension under MCP control. 20 tools covering the full ticket workflow:
  - *Read (12)*: `list_rst_tickets` (with JOIN'd dept/status/priority/staff/customer labels and rich filters incl. status, dept, priority, staff, customer, last_reply_customer, flagged, search, date range), `get_rst_ticket` (full state + resolved labels + custom field values + an `autoclose` block showing warning/close ETAs and what's blocking them), `get_rst_ticket_messages` (conversation thread with `is_staff` computed against the actual staff user_id set; RST system-message rows are decoded from PHP-serialized blobs into a clean `system_event {type, from, to, user_id}` object with a human-readable summary, and an `include_system_messages` flag toggles them), `get_rst_ticket_history`, `get_rst_ticket_notes`, `get_rst_ticket_files`, plus six lookups: `list_rst_departments`, `list_rst_statuses`, `list_rst_priorities`, `list_rst_staff` (with resolved Joomla user + group + departments-with-access; surfaces the trap that `tickets.staff_id` is actually a Joomla user_id, NOT the `_rsticketspro_staff` PK), `list_rst_groups` (25+ permission flags coerced to bools), `list_rst_custom_fields`.
  - *Write (8)*: `add_rst_ticket_reply`, `add_rst_ticket_note`, `update_rst_ticket` (with `{from, to}` diff per changed field), `close_rst_ticket` (matches admin "Close" — also stops time tracking), `reopen_rst_ticket`, `flag_rst_ticket`, `notify_rst_ticket` (autoclose-warning email with meaningful response when RST refuses to send), `delete_rst_ticket` (destructive — requires explicit `confirm:true`).
  - Architectural design: write tools call into RSTicketsPro's own `AdminModel` methods (`$model->reply()`, `$model->updateInfo()`, `$model->notify()`, `$model->toggleTime()`, etc.) instead of doing direct SQL. That means **every email notification, every ticket_history entry, every department-change ticket-code regeneration with custom-field migration, every staff-access validation, every time-tracking-stop-on-close happens automatically** — indistinguishable from a human staff member clicking through the admin UI.
  - Same separable-bundled-plugin pattern as `plg_system_csmcpforj4seo` — can split into its own paid SKU later without restructuring the core.
  - Tools live in `Cybersalt\Plugin\System\Csmcpforjrst\Tools\{Tickets,Lookups}\*`.

- **4SEO Business Profile typed wrappers** (`get_4seo_business_profile`, `set_4seo_business_profile`, `clear_4seo_business_profile`) — the third typed 4SEO write wrapper, alongside `set_4seo_meta_override` and `set_4seo_config`. Wraps the site-wide LocalBusiness profile that 4SEO emits as the `#defaultBusiness` JSON-LD node on every page (`#__forseo_config` row `scope='default', key='sd'`). Handles all the structural gotchas: `business_type` stored as single-element array (renderer does `array_pop`), `addressCountry` same, `logo` normalised to `{url, width, height}` object, opening hours fanned out from a clean `[{day, opens, closes}]` array into the 28-field `hoursMon1Opens`/etc. grid with `organizationHoursType=3` (CUSTOM) set automatically. **Solves the chicken-and-egg**: the row doesn't exist until a human saves the admin form, so the typed wrapper creates it from scratch with a defaults baseline pulled from 4SEO's own `config/sd.php` (matches what the admin UI would produce on first save). Closes the Westshore Eye Care site-wide LocalBusiness gap that ISSUE-3 + ISSUE-4 chased — the schemaorg plugin's site-wide schema is `name + image + sameAs` only (no phone/address/geo), so 4SEO's profile is the only working path to a full LocalBusiness on most Cybersalt sites. Source design grounded in the re-extracted 4SEO `vendor/weeblr/forseo/` tree that the May 11 snapshot missed.

### 🔧 Improvements

- **`get_rst_ticket_messages` now decodes RST's system-message rows** — RSTicketsPro stores status / department / priority / staff changes as synthetic `ticket_messages` rows with `user_id=-1` and a `serialize()`'d PHP array in the body (`a:4:{s:4:"type"...}`). The tool now `unserialize()`s those (with `allowed_classes => false` to prevent PHP gadget chains) and surfaces a clean `system_event {type, from, to, user_id}` object plus a human-readable `[system: status changed from 1 to 2 by user 8949]` body. New `include_system_messages` arg (default true) hides them when the caller wants just the actual conversation.

- **`get_rst_ticket` now includes an `autoclose` block** so the agent can answer "when will this ticket autoclose?" without having to know the config layout. Shows `enabled`, `automatic`, `warning_email_interval_days`, `close_interval_days`, `warning_sent`, `warning_eta` / `close_eta` (computed from `last_reply + interval`), and `blocked_by` when conditions short-circuit the flow (e.g. `last_reply_customer=1`, `autoclose_enabled=0`, `already_closed`).

- **Shared `withSiteAppContext()` trait helper** in `RSTicketsProBootTrait` — extracted from the inline pattern Tim's hand-patch landed on the ISSUE-5 fix. Wraps `Factory::$application` swap (api app ↔ real SiteApplication bootstrapped via the DI container) around any RST call that needs site routing for email-body URL construction (`Route::link('site', ...)`). Applied across `AddTicketReply`, `Update`, `Close`, `Reopen`, `Notify` so every email-firing write tool gets the swap without each one repeating 20 lines of try/finally noise.

### 🐛 Bug Fixes

- **`add_rst_ticket_reply` failed end-to-end in API context with "Error loading menu: api"** (or "Call to a member function getDepartments() on false"). RSTicketsPro 3.x's reply flow assumes a SiteApplication context — the api app the MCP endpoint runs under has no menu, no router, and no front-end MVC model search path. Three layers of failure: (1) `JModelLegacy::getInstance('Submit', 'RsticketsproModel')` returns false because front-end model paths aren't registered in api context, (2) even pre-loaded, the Submit model constructor needs site menu, (3) `RSTicketsProTicketHelper::saveMessage()` calls `Route::link('site', ...)` when building the notification email body which also needs site menu. **Fix:** bypass `RsticketsproModelTicket::reply()` and call `RSTicketsProTicketHelper::saveMessage()` directly, wrapped in the `withSiteAppContext()` swap. Consent gate + `onBeforeStoreTicketReply` / `onAfterStoreTicketReply` event triggers preserved from the original `reply()` flow. Validated end-to-end on virtuemarttemplates.net ticket TECH-0000000202: MCP reply posted → customer notification email delivered → inbound reply confirmed at `support@vmt` IMAP. Full investigation at [`resolved-issues/ISSUE-5-add_rst_ticket_reply-site-app-context.md`](resolved-issues/ISSUE-5-add_rst_ticket_reply-site-app-context.md).

- **`list_rst_tickets` / `get_rst_ticket` were returning `staff_name=null`** — the JOIN went `tickets.staff_id → _rsticketspro_staff.id → users.id` but `tickets.staff_id` is actually a Joomla `user_id`, not the `_rsticketspro_staff` PK (confirmed by reading `models/fields/staff.php` line 87 which emits `$user->id` as the dropdown option value). Direct `tickets.staff_id → users.id` JOIN now resolves correctly.

- **`update_rst_ticket` / `close_rst_ticket` / `reopen_rst_ticket` / `add_rst_ticket_reply` returned stale post-write state** — `RsticketsproModelTicket::getTicket($id)` caches statically per-id and the model's write methods don't invalidate the cache, so the post-write re-read returned the pre-write values. Now reads via a new `fetchTicketRow()` trait helper that bypasses the cache with direct SQL.

- **`update_rst_ticket` `changes` array was always empty** for staff/department changes — `RsticketsproModelTicket::updateInfo()` calls `$original->bind($data)` at model line 1189 to assemble the email payload, which mutates the original JTable in place. By the time the diff ran, `$original->$k` was the new value, not the original. Fix: snapshot the field values into a plain assoc array *before* calling `updateInfo()`, not a reference to the JTable.

- **`notify_rst_ticket` was always returning `notified:true`** regardless of whether `$model->notify()` actually sent the email. (RST refuses to send when `last_reply_customer=1`, `autoclose_sent=1` already, or `last_reply` too recent for `autoclose_email_interval`.) Now captures the model return value + emits an explanatory `note` listing the common refusal reasons.

### ⚠️ Known limitations carried forward

- **File attachments still not supported via `add_rst_ticket_reply`** — the front-end Submit-model upload path needs the same SiteApplication-swap treatment to work in API context, and we don't have a multipart upload path through the MCP layer anyway. Use the admin reply box for replies that need attachments.

- **`tickets.staff_id` naming trap** — the column stores a Joomla `user_id`, not the `_rsticketspro_staff` PK, despite the name. `list_rst_staff` output disambiguates with both `user_id` (use this for assignments via `update_rst_ticket(staff_id=...)`) and `staff_id` (the `_staff` table PK, mostly internal bookkeeping). Tool descriptions surface this so future agent calls don't repeat the original confusion.

## 🚀 Version 1.7.5 (May 13, 2026)

Three field-discovery fixes from the Westshore Eye Care SEO audit session on 2026-05-12. All three blocked different parts of getting a single site to a clean, fully-MCP-driven schema/meta state.

### 🐛 Bug Fixes

- **`set_4seo_meta_override` was writing custom values but never flipping `data.useTitle` / `useDescription` / `useRobots` / `useCanonical`.** 4SEO's renderer checks those `use*` flags at request time and skips the override if they're `0`, so every override written through this tool since v1.7.0 was a silent no-op even though the row looked correct (`status_title=2`, `data.custom.title="..."`, etc.). Both the insert and update branches now flip the matching flag whenever a custom value is supplied. Existing rows can be repaired by re-calling the tool with the same args — it'll preserve the custom value and now also set the flag. Response payload now includes `use_flags_set: ["useTitle", ...]` so callers can see exactly which flags landed. **Note for 4SEO Free sites:** per-page custom meta appears to be a 4SEO Pro feature — clean DB writes with no visible change on the page suggest the site has no `dlid` configured. Tool description now warns of this.

- **Dashboard token field's prompt preview now shows the substituted token live**, not just on copy. Previously the token field would correctly substitute on click, but the visible `<code>` block still showed the `<PASTE YOUR JOOMLA API TOKEN HERE>` placeholder — confusing because the user couldn't see that the substitution had actually happened. Now the preview re-renders on paste / clear / refresh-with-saved.

- **Substitution target is now visually highlighted** in the prompt preview — yellow `<mark>` on the substituted token (so the user can see "yes, this is what's about to be copied") and a muted grey highlight on the placeholder when no token is set yet (so the user can see "this is the spot that will be replaced"). Dedicated Atum dark-mode styles so the contrast stays readable.

### 📦 New Features

- **`update_menu_item` now exposes the menu item's `params` blob** — the JSON column on `#__menu` where Joomla stores every per-menu-item SEO setting (Browser Page Title, Meta Description, Meta Keywords, Robots, Page Heading, Show Page Heading, Page Class Suffix, Anchor Title, Force HTTPS). Two paths: **named args** (`browser_page_title`, `meta_description`, `meta_keywords`, `robots`, `page_heading`, `show_page_heading`, `page_class_sfx`, `menu_anchor_title`, `secure`) map to the right Joomla keys so the agent can't typo `menu-meta_description` as `meta_description`; **escape hatches** `params_set: object` and `params_unset: string[]` reach any other key. Named args take precedence on collision. Merge-by-default — existing keys not in the call are preserved byte-for-byte. `robots` enum-validated against Joomla's five valid values. Closes the home-page-meta-description gap on every Cybersalt client site.

- **`set_schemaorg_site_profile`** and **`get_schemaorg_site_profile`** — typed wrappers for the `plg_system_schemaorg` plugin's site-wide Organization/Person profile. Knows the *actual* four-key shape the plugin reads (`baseType`, `name`, `image`, `socialmedia`) — not the wrong `Organization_*` flat keys that look plausible but go nowhere. Hard-enforces `base_type` lowercase (`organization` / `person`); capital-O `Organization` silently kills the plugin's entire `@graph` output including per-article schemas — that case-sensitivity bug was the regression caught in last week's audit. Honours locked-plugin status the same way Joomla's admin UI does. Tool descriptions explicitly say the plugin does NOT support telephone/address/geo/email and point callers at 4SEO's Business Profile for full LocalBusiness coverage.

Tool count: 78 → 80. SchemaOrg domain count: 8 → 10.

## 🚀 Version 1.7.4 (May 11, 2026)

### 📦 New Features

- **Dashboard token field now persists across page refreshes** via `localStorage`. Previously the "paste your token here for one-click setup" field was ephemeral — paste, tab out, refresh to get the updated copy-paste prompt, and the token was gone. Now the field saves on input/change/blur, restores on load, and a new trash-icon button next to the eye-toggle clears it. A status line under the field tells you whether anything is currently saved ("Saved in this browser. The trash button clears it." / "No token saved. Paste one above — it stays in this browser only."). Stored per-browser/per-origin only — token never leaves the device, never hits the server.

### 🔧 Improvements

- **Token field UX confirmed as the Cybersalt house pattern**: `<input type="password">` rendering as asterisks plus an eye-icon reveal button (no CSS blur). Same trio — masked-by-default + eye reveal + localStorage persistence with trash to clear — will land on any future Cybersalt Joomla extension that asks for a secret.

## 🚀 Version 1.7.3 (May 11, 2026)

### 🐛 Bug Fixes
- **CRITICAL: Postflight failed on Joomla 6 with `Class "Joomla\CMS\Filesystem\File" not found`.** That class was deprecated in J4 and removed in J6 — installs on J5 worked (shim still present) but J6 sites (e.g. goatsatwork.ca, notesatwork.ca) hit the not-found error in the postflight try/catch, which surfaced as a yellow warning "cs-mcp-for-j postflight setup failed: …" and silently skipped the autoload-cache clear AND the plugin-enabling step. So on J6, the bundled `plg_webservices_csmcpforj` plugin stayed *disabled* after install, the MCP route 404'd, and the install looked broken. Fixed by replacing the one `File::delete()` call with plain `@unlink()` — no Joomla classes involved, works on every Joomla version. **If you installed v1.6.x–v1.7.2 on a Joomla 6 site, install v1.7.3 over top and the plugins will get re-enabled automatically.**

## 🚀 Version 1.7.2 (May 11, 2026)

### 📝 Documentation
- **`fetch_rendered_url` description** — added explicit guidance on path semantics (relative to Joomla install root, not server filesystem) and the 4SEO verification tip: fetch the SEF URL when checking whether a custom meta override landed, not the `index.php?option=...` form. 4SEO matches custom meta by SEF URL; on the option= form the override won't apply. Discovered during v1.7.1 live testing — burned 15 minutes on this confusion, the agent will too without the hint.
- **`set_4seo_meta_override` description** — added the same SEF-URL verification tip directly into the write tool's description so the agent reads it BEFORE the verify step, not after.

## 🚀 Version 1.7.1 (May 11, 2026)

### 🐛 Bug Fixes
- **`set_4seo_config` failed with "Incorrect integer value: 'json' for column format"** on first live use. The `format` column in `#__forseo_config` is `TINYINT NOT NULL DEFAULT 1`, not a VARCHAR — I'd been writing the string `"json"` into it. Fixed: `format` is now an integer enum (`1` = raw string, `2` = JSON). Auto-set to `2` when `value_object` is passed, `1` when `value` is passed. Explicit `format` override accepted as `{1, 2}` only. Also fixed the SQL: `format` is now written as a bare integer, not a quoted string. Bonus fix: the unrelated `lock_expires_at` column is nullable so we now insert `NULL` there instead of `'1970-01-01 00:00:00'`.

## 🚀 Version 1.7.0 (May 11, 2026)

Refactor of the 4SEO add-on now that we have 4SEO v6.12.0's full source available locally for design reference. Adds **5 typed tools** that wrap the highest-traffic 4SEO database tables, so an agent can manage per-page meta and 4SEO config without having to know about the three-layer envelope (`platform` / `auto` / `custom`) or the size-based column routing in `#__forseo_config`. The generic `query_4seo_table` / `insert_4seo_row` / `update_4seo_row` / `delete_4seo_row` escape hatches stay — they're how the agent can still reach the tables we haven't typed yet (rules, sitemaps, perf data, GSC, referrers, errors, links, images).

Tool count: 73 → 78. 4SEO domain count: 11 → 16.

### 📦 New Features

- **`list_4seo_meta_overrides`** — typed audit query over `#__forseo_custom_meta`. Returns each row's `content_id`, `url`, status flags, and the decoded `custom_title` / `custom_description` / `custom_robots` / `custom_canonical` so a query like "which articles have a custom SEO title?" is one call. Filters: `content_id_like`, `has_custom_title`, `has_custom_description`, `enabled`. Includes `total` for pagination.
- **`get_4seo_meta_override`** — read a single row. Accepts `content_id` (raw), `joomla_params` (object — tool alphabetises and concatenates), or `article_id` (shorthand for a `com_content` article). Surfaces all three layers (`platform`, `auto`, `custom`) separately so the agent doesn't have to walk the `data` JSON envelope.
- **`set_4seo_meta_override`** — the headline tool. Three-layer-aware upsert: supply `title` / `description` / `robots` / `canonical` and the tool sets them in the `custom` layer, bumps the corresponding `status_title` / `status_description` to `2` (custom), and leaves `platform` and `auto` layers untouched. Creates the row if missing (with empty `platform`/`auto` — 4SEO repopulates on next crawl), updates in place otherwise. The agent never has to know about the JSON envelope or hash columns.
- **`clear_4seo_meta_override`** — two modes: `reset_to_auto` (keeps row, wipes `custom` layer, resets statuses to `0` so 4SEO falls back to auto-detection) or `delete_row` (hard-removes the row entirely; 4SEO recreates on next crawl if the page is encountered again).
- **`set_4seo_config(key, value | value_object, scope?)`** — typed counterpart to `get_4seo_config`. Auto-encodes `value_object` to JSON; routes large payloads to the `large_value` mediumtext column when the string exceeds 16000 bytes; auto-detects `format="json"` when given a `value_object`. Upserts by `(scope, key)`.

### 🔧 Improvements

- All five new tools share a `ContentIdTrait` for parsing/building 4SEO's canonical `content_id` format (alphabetised `key=value` pairs joined with `&`, case-sensitive ASCII key sort to match 4SEO's `ksort` behaviour). Verified live against stage data on 2026-05-11: 1494 rows in `#__forseo_custom_meta` all match this format, and confirmed at `plugins/system/forseo/platform/components/content.php` line 225 in the 4SEO source.

### 🛡️ Design notes

- Why typed tools alongside generic CRUD instead of replacing the generic tools: the generic ones are still the safe path for any 4SEO table we haven't yet typed (about 20 of the 25 tables: rules, sitemaps, perf data, GSC daily aggregates, referrers, errors, links, images, etc.). Replacing them would lock the agent out of those tables. They're now placed last in the registration so the agent reaches for typed tools first.
- 4SEO does NOT use standard Joomla `Model` classes — there are none anywhere in the 4SEO PHP source. The "Option B" path from the original discovery (call into Weeblr's models via `bootComponent('com_forseo')->getMVCFactory()`) is unavailable. The path we took is Option A (DB-direct) but with typed wrappers built from the canonical `install.sql` schema, so the agent gets the ergonomics of Option B without needing the models.

## 🚀 Version 1.6.0 (May 9, 2026)

### 📦 New Features

- **`validate_jsonld(jsonld, expected_type?)`** — pre-flight JSON-LD shape validator. Returns `errors` (must-fix), `warnings` (should-fix), and `info` (cosmetic) messages. Designed to be called *before* `set_article_custom_jsonld_bulk` so a typo doesn't produce 500 silently-broken schema rows. Knows the required + strongly-recommended fields for: Article, BlogPosting, NewsArticle, FAQPage, Question, HowTo, Recipe, Event, Product, Offer, Review, JobPosting, LocalBusiness, Organization, Person, BreadcrumbList, VideoObject, Service, Book. Unknown @types pass without field-level checks (you can still get errors for missing @type, missing @context, or @graph wrapping). Tool count: 72 → 73.

## 🚀 Version 1.5.1 (May 9, 2026)

Patch release responding to v1.5.0 test feedback. Fixes the JSON-RPC corruption class of bug structurally and unblocks editing legitimately user-editable locked plugins via `set_plugin_params`.

### 🐛 Bug Fixes

- **Critical: stray PHP output corrupted JSON-RPC envelopes.** Any tool that triggered a PHP notice/warning (e.g. v1.5.0's `fetch_rendered_url` casting an array Content-Type header to string) emitted that warning text in front of the JSON-RPC response, which caused MCP clients to throw "Parse error: Unexpected token" on the otherwise-valid response. Wrapped `McpController::handle()` in `ob_start()` and discard the buffer before emitting JSON. Defends every tool — present and future — from this whole class of bug. Local cause in `FetchRenderedUrlTool` is also fixed (multi-value headers now joined with `, ` instead of cast to "Array").
- **`set_plugin_params` couldn't modify legitimately user-editable locked plugins** like `plg_system_schemaorg`. Joomla's own admin UI lets you edit these even though they're flagged `locked: true`. Added `allow_locked: true` flag to override the lock guard explicitly; `protected: true` plugins still get a hard refusal because those are genuinely dangerous to touch.

### 🔧 Improvements

- **`fetch_rendered_url` returns `jsonld_types`** — a flat, dedup'd, sorted array of every `@type` value across all JSON-LD blocks (recursively walking `@graph`). Common SEO check "did my X type land?" becomes `result.jsonld_types.includes("FAQPage")` instead of walking every block.
- **`set_article_custom_jsonld` and `set_article_custom_jsonld_bulk` descriptions** now warn against wrapping the supplied JSON-LD in a top-level `@graph` — Joomla 5.1+ merges each block into the page's existing `@graph` automatically, so wrapping yourself produces a graph-in-graph that's likely wrong.

## 🚀 Version 1.5.0 (May 9, 2026)

Feature release responding to the v1.4.x test feedback. Adds five new tools, total counts on paginated responses, and a token-substitute UI on the dashboard so non-technical users can copy a fully-ready prompt without manual editing.

Tool count: 67 → 72 (and three pre-existing paginated tools learned a `total` field).

### 📦 New Features

- **`get_plugin_params(folder, element)` / `set_plugin_params(folder, element, params, mode?)`** — generic plugin params read/write via direct DB. Unlocks site-wide schemaorg config (`folder=system, element=schemaorg`), router options, third-party plugin config, anything in the Options screen of any plugin. `set_plugin_params` defaults to merge mode (preserves keys you don't supply); refuses protected/locked core plugins.
- **`fetch_rendered_url(path, extract_jsonld?)`** — fetches a rendered page from the SAME Joomla site (same-origin only — no SSRF) so the agent can verify its writes worked. Optional `extract_jsonld=true` parses every `<script type="application/ld+json">` block in `<head>` and returns them as structured data — closes the verification loop for any Schema.org workflow.
- **`set_article_custom_jsonld_bulk(updates[])`** — bulk variant of `set_article_custom_jsonld` for sites with hundreds of articles. Per-item independent (one failure doesn't roll back the others), capped at 500 updates per call. Response gives per-item `ok`/`error`.
- **`get_4seo_config`** (4SEO add-on) — reads the actual 4SEO settings from `#__forseo_config` (where the real config lives — site-wide schema templates, default tags, scan rules) instead of the near-empty Joomla extensions row that `get_4seo_component_params` returns. Schema-agnostic: returns every column verbatim plus a `__parsed` field for any column whose value is JSON.
- **`total` field on paginated responses** — `list_articles`, `list_articles_with_schema`, `list_users`, and `query_4seo_table` now return a top-level `total` (the count across the whole filtered set, not just the current page) so the agent knows when pagination is complete without poll-till-empty.

### 🔧 Improvements

- **Token-substitute UI on the dashboard's prompt tab.** New "Optional: paste your token here for one-click setup" input field — when the user pastes a token, the Copy button substitutes the `<PASTE YOUR JOOMLA API TOKEN HERE>` placeholder before copying. Token never leaves the browser; the dashboard never sends it back to the server. The button label flips to "Copied with token included!" so the user knows substitution happened.

## 🚀 Version 1.4.2 (May 9, 2026)

Patch release responding to a real-world test of the v1.4.x onboarding prompt — first-call friction fixes, no new tools.

### 🐛 Bug Fixes
- **`list_articles_with_schema` summary was misleading.** It counted only the rows on the current page, so calling with `limit:1` returned `with_schema:0, without_schema:1` regardless of how many articles actually had/lacked schema across the full filtered set. Fixed: summary now runs `COUNT(*)` queries across the whole filtered set, independent of pagination. Response also adds a top-level `total` so the agent knows the full filtered count.
- **Onboarding prompt: curl example mangled JSON arguments containing nested quotes.** First-time callers hit `Parse error: Syntax error` the moment a tool argument was a JSON object. Switched the example from inline `-d '{"…"}'` to `--data-binary @-` with a single-quoted heredoc — survives any payload.
- **Onboarding prompt: didn't explain that `tools/list` and `tools/call` return different response shapes.** `tools/list` returns `result.tools` directly (no content wrap); `tools/call` wraps in `result.content[0].text`. The prompt only described the `tools/call` shape, so first-time callers hit a parse mismatch on the very first response. Documented both.
- **Onboarding prompt: claude.ai branch was wrong.** Said to "grab the JSON snippet from the dashboard" — claude.ai connectors actually use separate URL + auth-header fields, not a JSON snippet. Updated the prompt to give claude.ai users the URL and auth header directly, with the JSON snippet path reserved for Claude Desktop.
- **Onboarding prompt: prompt-injection guard added to `claude mcp add` self-install offer.** Tells Claude to verify the URL in the install dialog matches the user's own site domain before approving — defense against a maliciously edited copy of the prompt that redirects the install to a hostile endpoint.

## 🚀 Version 1.4.1 (May 9, 2026)

### 🐛 Bug Fixes
- **"Generate / view API token" link landed on the user list, not the admin's own profile.** The link was `task=user.edit` with no id, so Joomla's controller fell through to the user list instead of opening the current admin's profile (where the API Token tab lives). Fixed by computing the current admin's user id and substituting it into `task=user.edit&id={N}`. Both link instances on the dashboard also now open in a new tab so the user keeps the dashboard open while copying the token. Same pattern as cs-template-integrity.

## 🚀 Version 1.4.0 (May 9, 2026)

### 🐛 Bug Fixes
- **Tabs not switching on dashboard.** The Bootstrap `bootstrap.tab` JavaScript module isn't auto-loaded in Joomla admin views; without an explicit `WebAssetManager::useScript('bootstrap.tab')` opt-in, clicking a tab only changed the URL hash and the panel never activated. Fixed.

### 🔧 Improvements
- **Tab order swapped.** The copy-paste prompt is now the **default tab** (renamed "Copy a prompt into Claude (recommended)") because it's the easier path for non-technical users. The JSON snippet path moves to the second tab ("MCP Connector (manual config)").
- **Self-installing prompt.** The copy-paste prompt now includes a closing instruction that tells Claude: after the user confirms the connection works, offer to install this site as a permanent MCP connector via `claude mcp add` (Claude Code only). The user just says "make it permanent" and Claude runs the install command (with the standard approval dialog before any shell command runs). After a Claude restart, the site appears as native MCP tools in every conversation — no prompt needed ever again. One paste = either a one-off use OR a permanent install, user's choice via a single follow-up sentence.
- **Setup card framing** rewritten to make the recommended path obvious and explain the bonus self-install behavior up front.

## 🚀 Version 1.3.0 (May 9, 2026)

### 📦 New Features
- **Two-method dashboard for connecting Claude.** The dashboard now offers two clear setup paths in a tabbed UI:
    - **Method 1 — MCP Connector.** The traditional MCP setup: copy a JSON snippet (pre-filled with the site's URL), paste into your Claude client's config (Claude Desktop / claude.ai connectors / `claude mcp add`), restart. One-time, persistent.
    - **Method 2 — Copy-paste prompt.** A new "no client config required" path. The dashboard generates a complete instruction prompt (site URL, endpoint, auth, JSON-RPC shape, tool surface summary, workflow rules, example curl). Paste into a fresh Claude Code conversation and Claude talks to the MCP endpoint directly via curl. Best for one-off tasks or when you can't change client config.
- **Copy buttons** with "Copied!" feedback on both setup payloads (clipboard API with text-selection fallback).

### 🔧 Improvements
- Dashboard tools list and setup payloads are both auto-generated from the live tool registry — the prompt always reflects what's actually installed.

## 🚀 Version 1.2.0 (May 9, 2026)

### 📦 New Features
- **4SEO add-on** (`plg_system_csmcpforj4seo`) — first paid-add-on-shaped sub-plugin (bundled with the package for testing; structured so it can be split into its own SKU later). Adds 10 tools that introspect and modify the Weeblr 4SEO extension (`com_forseo`) directly against `#__forseo_*` since 4SEO ships no public Web Services API:
    - `list_4seo_tables` — every #__forseo_* table on the site
    - `describe_4seo_table` — column schema for one table
    - `count_4seo_rows` — row counts across all 4SEO tables (health snapshot)
    - `get_4seo_component_info` — is com_forseo installed/enabled, which Weeblr siblings are alongside
    - `get_4seo_component_params` — read com_forseo Options screen settings
    - `set_4seo_component_params` — merge-update com_forseo Options screen settings
    - `query_4seo_table` — safe parameterised SELECT (structured WHERE clauses, no raw SQL, restricted to `forseo_*`)
    - `insert_4seo_row` — safe single-row INSERT
    - `update_4seo_row` — safe single-row UPDATE (refuses if WHERE matches >1 row)
    - `delete_4seo_row` — safe single-row DELETE (refuses if WHERE matches >1 row)
- All 4SEO write tools refuse any table not starting with `forseo_`, so misuse can't reach #__users / #__content / etc.

### 🐛 Bug Fixes
- **Memory exhaustion in dashboard load (CRITICAL).** `RegisterToolsEvent` extended `Joomla\CMS\Event\AbstractEvent`, whose argument processor walks argument values and recursed catastrophically when the `registry` argument carried 50+ tool instances each holding a `DatabaseInterface` reference. Fixed by switching to the simpler `Joomla\Event\Event` base class and refactoring the dashboard to read tool metadata statically from the bundled plugin classes (no event dispatch from the dashboard at all).

### 🔧 Improvements
- Tool count: 57 → 67. Domain count: 12 → 13.
- Server protocol identifier bumped to `cs-mcp-for-j 1.2.0`.

## 🚀 Version 1.1.0 (May 9, 2026)

### 📦 New Features
- **Schema.org / SEO tool domain** (6 tools) wrapping Joomla's CORE `plg_system_schemaorg` system:
    - `list_schema_types` — canonical type list with typical fields per type
    - `list_articles_with_schema` — audit which articles have/lack structured data, with summary by type
    - `get_article_schema` — read the stored schemaorg row for a content item
    - `set_article_schema` — set/replace any of Article, BlogPosting, Book, Event, JobPosting, Organization, Person, Recipe, Custom
    - `set_article_custom_jsonld` — convenience for Custom type: pass a JSON-LD object directly, no need to stringify
    - `clear_article_schema` — remove the row (matches Joomla's `schemaType=None` behaviour)
- Writes go directly to `#__schemaorg` in the same shape Joomla's `Schemaorg::onContentAfterSave` hook produces. The rendered `<script type="application/ld+json">` blocks pick up changes on the next page load with no cache invalidation needed.

### 🔧 Improvements
- Tool count: 51 → 57. Domain count: 11 → 12.
- Server protocol identifier bumped to `cs-mcp-for-j 1.1.0`.

## 🚀 Version 1.0.0 (April 25, 2026)

Initial release.

### 📦 New Features
- **MCP endpoint**: Streamable-HTTP MCP server at `/api/index.php/v1/mcp`, authenticated by Joomla API token (`X-Joomla-Token` or `Authorization: Bearer`).
- **JSON-RPC 2.0 server**: `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`. Supports single messages and batches.
- **ACL gating**: `csmcpforj.use` (read-only tools) and `csmcpforj.write` (mutating tools). Super Users / Administrators / Managers always pass; other groups need explicit grant.
- **Tool registry**: Plugins extend the surface by subscribing to the `onCsMcpRegisterTools` event and registering classes extending `AbstractTool`.
- **Bearer translation**: System plugin rewrites `Authorization: Bearer <token>` to `X-Joomla-Token` for the MCP route only.
- **Admin dashboard**: Endpoint URL, copy-paste client config, permissions table, and grouped tool list (51 tools across 11 domains).
- **51 built-in tools** across:
    - Articles (6) — list, get, create, update, delete, list_categories
    - Categories (5) — list_categories_in, get, create, update, delete (works for any extension)
    - Tags (5) — list, get, create, update, delete
    - Menus (6) — list_menus, list/get/create/update/delete menu_item
    - Users & Access (7) — list, get, create, update, delete; list_user_groups, list_access_levels
    - Modules (6) — list, list_module_positions, get, create, update, delete
    - Extensions (3) — list_extensions, list_plugins, set_extension_enabled
    - Templates (2) — list_template_styles, set_default_template_style
    - Languages (2) — list_languages, list_content_languages
    - Custom Fields (4) — list, get, create, set_custom_field_value
    - System (5) — get_joomla_version, get_site_info, list_scheduled_tasks, check_for_updates, clear_cache

### 🔍 Security
- Per-tool permission gate runs before every `tools/call` execution.
- Component ships with `access.xml` so per-action permissions are visible in **System → Permissions** out of the box.
- All article/category/tag/menu/user/module/field writes go through the corresponding com_* Administrator model so workflow, asset, and ACL side effects stay consistent.
- All database access uses `quoteName()` and parameterised values — no string concatenation into SQL.
- `delete_user` refuses to delete the calling user.
- `set_extension_enabled` refuses to touch protected/locked core extensions.
- `get_site_info` deliberately omits secrets (DB password, mailer credentials, captcha keys).
