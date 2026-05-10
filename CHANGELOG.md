# Changelog

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
