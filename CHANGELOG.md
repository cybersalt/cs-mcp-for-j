# Changelog

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
