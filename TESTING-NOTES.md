# Testing Notes — live MCP exercise against stage 2026-05-11

This replaces the earlier TESTING-NOTES (which was a morning-checklist written when I couldn't find the stage URL). Now the live MCP has been exercised end-to-end from VS Code via curl. Findings below.

---

## Where it's installed

| Stage URL | Joomla | PHP | cs-mcp-for-j |
|---|---|---|---|
| `https://www.cybersalt.com/stageit/api/index.php/v1/mcp` | 5.4.5 Stable (Kutegemea) | 8.3.31 | **v1.5.0 installed** (latest released is v1.6.0 — see "Pending install" below) |

**URL note:** `cybersalt.com/stageit/...` (no `www.`) 301-redirects, and the redirect *strips* `/stageit/` from the path so you end up at `www.cybersalt.com/api/...` (which is the prod Joomla install, which doesn't have cs-mcp-for-j). The `www.` prefix on the request bypasses the redirect and the route resolves correctly to the stage install. **Always use `www.cybersalt.com/stageit/`** when calling the API.

Token to use: the `CYBERSALT_COM_API_TOKEN` from settings.json (user 408). The `CYBERSALT_ORG_API_TOKEN` (user 62) works against the cybersalt.org Joomla, not stage.

---

## What's confirmed working (against v1.5.0 live)

All read-only tools that don't have known v1.5.0 bugs:

- `initialize`, `tools/list` — 72 tools confirmed, full domain breakdown matches what we shipped (Articles 7, Categories 6, Tags 5, Menus 6, Users 7, Modules 6, Extensions 5, Templates 2, Languages 2, Custom Fields 4, System 6, Schema.org 5, 4SEO 11).
- `get_joomla_version` — returns 5.4.5/8.3.31/Kutegemea. *Bug:* `mcp_extension` field is hardcoded to `"cs-mcp-for-j 1.0.0"` in [GetJoomlaVersionTool.php](packages/plg_system_csmcpforj/src/Tools/System/GetJoomlaVersionTool.php) — never updated as we bumped versions. One-line cosmetic fix (next patch).
- `get_4seo_component_info` — 4SEO v6.12.0.2692 installed and enabled. Whole Weeblr family present (4AI, 4Analytics, 4SEF, 4Command, 4Logs).
- `list_4seo_tables` — **25 tables**, prefix `stg_j6twa_`. Key ones: `forseo_config`, `forseo_rules`, `forseo_custom_meta`, `forseo_custom_social`, `forseo_sitemaps`, `forseo_pages`, `forseo_keystore`, plus the GSC + perf data tables.
- `describe_4seo_table` — captured full schemas for `forseo_config` (11 cols: id/scope/key/value/large_value/user_id/version/lock/lock_expires_at/format/modified_at — it's a scoped key-value store) and `forseo_rules` (12 cols: id/type/source/title/rule[varchar 14000 JSON blob]/last_hit/…). **This is the data we needed for designing precise 4SEO tools later.**
- `get_4seo_config` — returns 6 config rows. First row is the `pages` config blob — main 4SEO crawler/page-collection config. **`canonicalRootUrl: "https://www.cybersalt.com/"`** is set to *prod's* URL on the stage site, which may be intentional (stage doesn't want SEO crawlers indexing it under its own URL).
- `get_plugin_params(folder:"system", element:"schemaorg")` — returns the live site-wide schema config:
    - `baseType: "organization"`
    - `name: "Cybersalt Consulting"`
    - **`image: ""`** ← the missing publisher logo the reviewer flagged. This is the field to set once we have v1.5.1's `allow_locked: true` available.
    - 3 social media URLs (Facebook, X, LinkedIn)
    - `locked: true` — explains why v1.5.0's `set_plugin_params` refuses to edit.
- `list_articles_with_schema` — returns `total: 803` (now 803 articles on stage, +4 since the v1.5.0 reviewer's session). 4 articles still have schema attached: 771, 769, 755, 754 (the leftovers).
- `list_articles` — returns `total: 803`, normal Joomla content data.
- `get_article_schema(item_id:771)` — returns the BlogPosting JSON the reviewer wrote, then after our bulk write reads back VideoObject (see below).
- `set_article_custom_jsonld_bulk` — **end-to-end write+read loop verified.** Wrote VideoObject schemas to articles 771 and 755 in one call. Response: `{ok: true, attempted: 2, inserted: 0, updated: 2, failed: 0, results: [...]}`. Read-back on 771 confirmed the new payload is stored.

---

## Bugs confirmed live in v1.5.0 (fixed in v1.5.1 — pending install)

1. **`fetch_rendered_url` corrupts the JSON-RPC response.** First curl hit returns:
   ```
   <br />
   <b>Warning</b>:  Array to string conversion in
   <b>.../FetchRenderedUrlTool.php</b> on line <b>100</b><br />
   {"jsonrpc":"2.0","id":1,"result":...}
   ```
   The PHP warning text prefixes the JSON, breaking strict MCP parsers. v1.5.1 wraps `McpController::handle()` in `ob_start()` to swallow the warning AND fixes the local cause (multi-value Content-Type header was cast directly to string). **`fetch_rendered_url` is effectively unusable on stage until v1.5.1 installs.** Workaround: parse manually starting from the first `{`.

2. **`set_plugin_params` refuses `plg_system_schemaorg`** because it's `locked: true`. Error: `"Refusing to modify protected or locked core plugin."` v1.5.1 adds `allow_locked: true` flag to override. Until v1.5.1 installs, we can't edit the schemaorg image/baseType/etc. via MCP.

3. **`list_articles_with_schema` summary reflects filter+page, not full set.** When I called with `has_schema:false, limit:1`, the summary said `with_schema: 0` even though there are 4 articles with schema (verified by a second call with `has_schema:true`). The summary correctly counts ONLY the rows matching the filter, but agents naturally interpret it as a full-table overview. v1.5.1 changes the summary to count across the whole table independent of pagination, AND adds `total` at top level (which v1.5.0 happens to already have for this tool — verified `total: 799` and `total: 4` in the two calls).

---

## Update 2026-05-11 afternoon — v1.6.0 installed, all fixes verified live

Tim installed v1.6.0 on stage. Re-ran the test sequence:

1. `initialize` returns `serverInfo: cs-mcp-for-j 1.6.0`. Confirmed.
2. **`fetch_rendered_url` now returns clean JSON** — no PHP warning prefix. `content_type: "text/html; charset=utf-8"` (was "Array" in v1.5.0). The `ob_start` controller guard works.
3. **`validate_jsonld` works** — on a deliberately broken `{"@type":"VideoObject","name":"test"}` payload, returned 3 errors (missing required description/thumbnailUrl/uploadDate) and 5 warnings (missing @context, missing recommended fields). Exactly the pre-flight check the design intended.
4. **`list_articles_with_schema` summary now correct** — with no filter: `total: 803, with_schema: 4, without_schema: 799, by_type: {Article: 1, Custom: 3}`. Cross-table fix landed.
5. **`set_plugin_params` with `allow_locked: true` works** — fixed the headline SEO gap. Set the Organization logo on plg_system_schemaorg via MCP, verified the change persisted via `get_plugin_params`, verified the rendered page now includes the logo + image properties in the Organization node of the JSON-LD `@graph`. Full SEO loop closed end-to-end.

### Publisher-logo workflow (the actual proof)

This is the workflow the reviewer flagged as the highest-impact gap. Did it through MCP only:

```
1. fetch_rendered_url path:"index.php" include_html:true
   → regex'd HTML for logo references
   → found /stageit/images/cybersalt/cybersalt-logo-tr.png

2. set_plugin_params
     folder: "system"
     element: "schemaorg"
     allow_locked: true
     params: { image: "https://www.cybersalt.com/stageit/images/cybersalt/cybersalt-logo-tr.png" }
   → ok: true, changed_keys: ["image"], mode: "merge"

3. get_plugin_params → confirms image stored in params

4. fetch_rendered_url path:"index.php" extract_jsonld:true
   → Organization node now has:
       logo: {@type:"ImageObject", url:"...", contentUrl:"..."}
       image: {@id:".../ImageObject/logo"}
```

Wall-clock time: under 30 seconds. Without MCP this would have been: log into admin → System → Plugins → search → open schemaorg plugin → click into params → paste URL → save → flip to a public page → view source → grep for ld+json → eyeball the Organization node. Easily 5+ minutes if you're unfamiliar with the admin path.

### Minor finding for next patch

`get_joomla_version` returns `mcp_extension: "cs-mcp-for-j 1.0.0"` — hardcoded string in `GetJoomlaVersionTool.php` that never got updated. One-line fix. Defer to next patch.

### `fetch_rendered_url` path semantics

When I passed `path: "/stageit/"` the tool concatenated it onto `Uri::root()` (which is `https://www.cybersalt.com/stageit/`) yielding `https://www.cybersalt.com/stageit/stageit/` and a 404. Path is treated as relative-to-site-root, not absolute server path. Updated description text would help — "path is relative to the Joomla install root, e.g. `index.php` or `images/foo.jpg`, not the server's webroot." Tiny doc-only fix, also defer.

---

## Done — v1.6.0 installed and the publisher-logo workflow run live (see above)

---

## Data captured for future 4SEO add-on design

The schema discovery exercise yielded these tables we now know precisely (full columns documented via `describe_4seo_table`):

| Table | Use | Notes |
|---|---|---|
| `forseo_config` | Site-wide settings | Scoped key-value store; values up to varchar(16000); fallback to `large_value` mediumtext; `format` column likely indicates how to decode the value |
| `forseo_rules` | Targeting rules for SEO overrides | Each row is a rule with type/source/title and a `rule` varchar(14000) JSON blob; `last_hit` tracks usage |
| `forseo_custom_meta` | Per-page meta overrides | (schema not pulled yet — recommended next step) |
| `forseo_custom_social` | Per-page Open Graph / Twitter Cards | (not pulled) |
| `forseo_sitemaps` | Sitemap configurations | (not pulled) |
| `forseo_pages` | Discovered/crawled pages | Populated by 4SEO's crawler |
| `forseo_keystore` | API keys for integrations | (Yoast import, etc., probably) |

When designing the second-wave 4SEO tools (after v1.6.0 install), the natural next tools are:
- `list_4seo_rules` / `create_4seo_rule` / `update_4seo_rule` / `delete_4seo_rule` (purpose-built CRUD against forseo_rules — easier than `query_4seo_table` for agents)
- `set_4seo_config(key, value, scope?)` — a typed wrapper around forseo_config that knows the JSON-decode-where-applicable convention
- `list_4seo_meta_overrides` (against forseo_custom_meta) — for the per-page meta workflow
- `list_4seo_sitemaps` + `update_4seo_sitemap`

Each of these wraps a known table that we now have schema for. Building them is straightforward.

**If 4SEO's source files (`administrator/components/com_forseo/`) were available locally**, these could be Option-B tools that call 4SEO's PHP models directly (robust to schema changes) instead of DB-direct (brittle on 4SEO upgrades). For all *future* third-party-extension MCPs (Akeeba, VirtueMart, RSForm, whatever), that's the right path — drop their files where the assistant can `Read` them and the tools become structurally more reliable.

---

## Workflow that worked end-to-end live

```
1. tools/list                        → 72 tools, 13 domains
2. get_4seo_component_info           → 4SEO installed v6.12.0
3. list_4seo_tables                  → 25 tables
4. describe_4seo_table forseo_config → schema captured
5. describe_4seo_table forseo_rules  → schema captured
6. get_4seo_config limit:5           → 6 config rows, first one is page-collection config
7. get_plugin_params system/schemaorg → site-wide schema config, image="" (the gap)
8. list_articles_with_schema         → 803 articles, 4 with schema
9. get_article_schema item_id:771    → BlogPosting (reviewer's test)
10. set_article_custom_jsonld_bulk   → updated 771 + 755 with VideoObject
11. get_article_schema item_id:771   → confirms VideoObject is now stored
```

11 calls. No environment setup beyond URL + token in a `curl` script. This is the workflow this extension was built for.

---

## Roadmap

**Immediate (Tim, when ready):**
- Install v1.6.0 zip on stage (eliminates the JSON-RPC corruption bug + unlocks schemaorg editing + adds validate_jsonld).

**Once installed, agent can:**
- Set the publisher logo image on plg_system_schemaorg.
- Re-run fetch_rendered_url to verify rendered JSON-LD on actual pages.
- Run a 659-video VideoObject backfill if you want — the bulk tool was just proven end-to-end with two updates in one call.

**Next-wave development:**
- Per-table 4SEO tools (rules CRUD, custom_meta CRUD, sitemap CRUD) — schema is now known.
- Fix `get_joomla_version` hardcoded `cs-mcp-for-j 1.0.0` string. Patch-sized.
- The agent-friendly 4SEO local-files question: if you drop 4SEO's `com_forseo` PHP into a directory I can read, I can write Option-B tools (calling into Weeblr's own models) instead of Option-A DB-direct CRUD. Worth doing before the rules/meta/sitemap CRUD wave.
