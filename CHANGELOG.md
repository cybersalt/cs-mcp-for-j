# Changelog

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
