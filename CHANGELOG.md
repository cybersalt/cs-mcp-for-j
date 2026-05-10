# Changelog

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
