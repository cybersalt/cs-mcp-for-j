# Changelog

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
