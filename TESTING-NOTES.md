# Testing Notes — overnight session 2026-05-09 / 10

What got built while you were asleep, what's deployed, what needs to be installed/tested, and the prompts to paste into your open Claude window to exercise everything.

---

## What shipped tonight

| Version | What | Why |
|---|---|---|
| v1.5.1 | Patch — `ob_start` guard at controller; `set_plugin_params` `allow_locked` flag; `jsonld_types` array on `fetch_rendered_url`; @graph warnings in JSON-LD writer descriptions | Test feedback flagged a critical PHP-warning-corrupts-JSON-RPC bug, locked-plugin block on `plg_system_schemaorg`, and missing types-summary on rendered-page reads. |
| v1.6.0 | Feature — `validate_jsonld(jsonld, expected_type?)` tool | Pre-flight shape validator so a typo doesn't produce 500 silently-broken rows on a bulk write. |

Repo: https://github.com/cybersalt/cs-mcp-for-j
Releases: https://github.com/cybersalt/cs-mcp-for-j/releases

**Install v1.6.0 on stageit.** v1.5.0 has the JSON-corruption bug that broke `fetch_rendered_url`; v1.5.1 fixes it; v1.6.0 adds `validate_jsonld` on top. Anything older has at least one bug the test feedback caught.

---

## What's NOT done that I tried

**I couldn't run the MCP myself from this Claude Code session.** No MCP connector for cs-mcp-for-j is registered in this session, so I'd have had to fall back to curl. I tried to find the stageit URL via probing and got nowhere:

- `stageit.cybersalt.com` doesn't resolve (no subdomain by that name)
- `cybersalt.com/stageit/...` rewrites to `www.cybersalt.com/api/index.php/v1/mcp/`, which 404s — the MCP route isn't registered on prod
- `test.cybersalt.com` redirects to a WordPress site at butlerandquinn.com (different client?)
- `staging.*`, `stage.*`, `dev.*` subdomains all fail DNS

So I don't know the stageit URL. You'll need to either tell me in the morning, or just run the tests yourself in your already-connected Claude window using the prompts in the next section.

---

## Prompts to paste into your already-connected Claude window

These exercise the new v1.5.1 + v1.6.0 surface end-to-end. Paste each one in turn after the previous completes.

### 1. Confirm v1.6.0 is what's live + count tools

> Run `tools/list` again and tell me the total count. Group by domain — I want to see if the count went up since last we tested. Also confirm `validate_jsonld`, `get_plugin_params`, `set_plugin_params`, `fetch_rendered_url`, `set_article_custom_jsonld_bulk`, `get_4seo_config` all show up. Say which (if any) are missing.

Expected: 73 tools total (up from 67). All six should be present. If any are missing, the install isn't current.

### 2. Verify the JSON-RPC corruption bug is gone

> Run `fetch_rendered_url` on the homepage with `extract_jsonld: true` and `include_html: false`. Check carefully whether the JSON response is well-formed (not prefixed with any "Warning:" or "Notice:" text). Tell me the `content_type` field — it should be a plain string like "text/html; charset=utf-8", not "Array". Also tell me the new `jsonld_types` array — that's a flat list of every @type across all blocks.

Expected: clean JSON, `content_type` is a real Content-Type string, `jsonld_types` lists every type. If you see `"content_type": "Array"` or any text before the `{` of the JSON, v1.5.1 didn't install.

### 3. Verify `set_plugin_params` can now edit `plg_system_schemaorg`

This is the actual workflow gap the v1.5.0 reviewer flagged.

> Read `plg_system_schemaorg`'s params with `get_plugin_params(folder:"system", element:"schemaorg")`. Tell me what's there. Then I want to add a logo URL — try writing to it with `set_plugin_params(folder:"system", element:"schemaorg", params:{"image":"images/logo.png"})`. If it refuses with "Plugin is locked", retry with `allow_locked: true` and confirm it succeeded. Then read the params back to verify the change persisted.

Expected: the first write fails with the locked-plugin message (which now mentions allow_locked); the retry with allow_locked: true succeeds; the read-back shows image now contains "images/logo.png" (but actually pick the real logo path you want — or just use the one that's there if you don't want to change anything for real).

### 4. Verify `jsonld_types` array on render fetch

> Fetch the rendered output of article 4 (the FAQ article) with `fetch_rendered_url path:"/index.php?option=com_content&view=article&id=4" extract_jsonld:true include_html:false`. Just tell me what's in `jsonld_types` — that should be the answer to "what schemas are visible on this page?"

Expected: a flat array including `"FAQPage"` if our previous `set_article_custom_jsonld` test landed correctly. Plus `Organization`, `WebSite`, `WebPage`, `BreadcrumbList`, etc. depending on what 4SEO and Joomla core are emitting.

### 5. Pre-flight a JSON-LD payload before bulk

> I want to add VideoObject schema to article 771 (the test row from earlier). Before writing, validate this payload with `validate_jsonld`:
> ```json
> {
>   "@context": "https://schema.org",
>   "@type": "VideoObject",
>   "name": "Test video about goats",
>   "description": "A short video.",
>   "thumbnailUrl": "https://goatsatwork.ca/images/goat.jpg",
>   "uploadDate": "2026-05-09"
> }
> ```
> Tell me what `errors`, `warnings`, and `info` come back. If `errors` is empty, write it to article 771 with `set_article_custom_jsonld`.

Expected: `errors: []` (all four required VideoObject fields are present), maybe `warnings` mentioning recommended fields like contentUrl, embedUrl, duration, publisher. Then the write should succeed.

### 6. Bulk validate-then-write pattern

> For each of articles 771, 755, 754, build a small VideoObject payload (different name per article — make them up). Validate each with `validate_jsonld` first. If all three pass, write all three at once with `set_article_custom_jsonld_bulk`. Confirm the per-item `ok` came back true for each.

Expected: three successful inserts/updates in one round-trip.

### 7. Verify writes via the rendered page (full SEO loop closed)

> Fetch the rendered page for article 771 with `extract_jsonld: true` and tell me whether `VideoObject` appears in `jsonld_types`. That confirms our write actually rendered.

Expected: yes. This is the "write → render → verify" loop the v1.5.0 reviewer said was the missing piece.

### 8. (Optional) Clean up test data from earlier

> Earlier we left BlogPosting test rows on articles 771, 755, 754 from a prior test session, then I just put VideoObject on top of them. The VideoObject overwrote the BlogPosting (since each article has one schemaorg row). If you want to clear the test data entirely, call `clear_article_schema` for each id. Or leave them — the site is for testing.

Your call, no expected answer.

---

## Findings to bring to the next session

If anything in the above prompt sequence fails, the response message will tell you which version of the extension is on stage. Reply with the failing tool's response and I'll patch it next session.

If everything passes, the natural next conversations are:

1. **The 659-video VideoObject backfill** the reviewer mentioned. Two-call job now (chunk into ≤500, then a second call) instead of 659 sequential.
2. **The Joomla Brain MCP guide** I wrote tonight (`JOOMLA-MCP-SERVER-GUIDE.md` in the Joomla-Brain repo) — the next time you build an MCP for any other Joomla extension, that doc starts you 80% of the way. Worth a read when you have coffee in hand.
3. **What other Joomla extensions are next on the MCP add-on list?** Akeeba? VirtueMart? RSForm? The 4SEO add-on is the proof of concept; the model scales.

---

## Roadmap items deferred from tonight (in test-feedback priority)

These came up in the last test report but I parked them rather than ship rushed:

- **`set_global_config(key, value)`** — the reviewer suggested a whitelisted-key version (`debug`, `sef`, `cache`, etc.). I'd lean toward shipping per-key dedicated tools (`set_debug_mode`, `set_sef_enabled`, ...) so each one has its own description and the agent can't misuse a generic setter. That's a v1.7 conversation.
- **Bulk variants of more write tools** — only `set_article_custom_jsonld` got a `_bulk` cousin tonight. `set_article_schema_bulk`, `update_article_bulk` etc. follow the same pattern; ship them when a workflow actually needs them.
- **Add-ons split into their own repos** — `cs-mcp-for-j-4seo` is structurally a separate plugin already (`plg_system_csmcpforj4seo`) but bundled in the package for convenience. When you're ready to monetize, splitting it into its own repo is a half-day job.

---

## Status check before you start

```bash
# Quick smoke test from a terminal — confirm the version on stage
curl -sS -X POST <YOUR_STAGE_URL>/api/index.php/v1/mcp \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer <YOUR_TOKEN>" \
     --data-binary @- <<'EOF'
{"jsonrpc":"2.0","id":1,"method":"initialize"}
EOF
```

`serverInfo.version` in the response will be `"1.6.0"` if v1.6.0 is installed. If it's still `"1.5.0"` or earlier, install the latest zip first.

Have a good morning.
