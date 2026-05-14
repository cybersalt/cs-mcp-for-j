# `update_menu_item` doesn't expose menu item `params` — can't set Browser Page Title, Meta Description, robots, page heading, or any of the per-menu-item SEO fields

## Summary

`update_menu_item` accepts a useful set of top-level columns (`title`, `alias`, `menutype`, `parent_id`, `link`, `published`, `home`, `language`, `access`, `note`, `browserNav`, `template_style_id`) but does **not** expose the `params` JSON blob on the `#__menu` row. That blob is where Joomla stores every per-menu-item SEO setting that templates and `plg_system_schemaorg` actually read:

| Param key | What it controls | SEO impact |
|---|---|---|
| `page_title` | Browser Page Title override (the `<title>` tag) | 🔴 Critical |
| `menu-meta_description` | Meta Description override | 🔴 Critical |
| `menu-meta_keywords` | Meta Keywords | 🟡 Bing only, low |
| `robots` | Per-page robots directive (`noindex`, `nofollow`, etc.) | 🔴 Critical for staging/admin links |
| `secure` | Force HTTPS | 🟡 |
| `page_heading` | H1 / page heading text (overrides the article title display) | 🟡 |
| `show_page_heading` | Whether to render the heading at all | 🟡 |
| `pageclass_sfx` | Body class suffix for CSS targeting | 🟢 |
| `menu-anchor_title` | `<a title="...">` tooltip on the menu link itself | 🟢 |

`get_menu_item` already returns the decoded `params` object, so the read side works. There's just no write path.

## Real-world hit

Westshore Eye Care, 2026-05-12. Home menu item (id=101, featured view) was rendering `<title>Home</title>` with no meta description — the single biggest on-page SEO issue on the whole site. The natural fix is to set `params.page_title = "Westshore Eye Care — Optometrist in Colwood, BC"` and `params.menu-meta_description = "Comprehensive eye exams..."` on menu item 101. The MCP-driven session was the right tool for the job — bulk updates, consistent voice, repeatable — but I had to leave it as a manual TODO for the human to do in the admin UI because `update_menu_item` can't touch `params`.

Same issue applies to component menu items where `params` carries view-specific settings (article view's `show_title`, blog view's `num_intro_articles`, etc.) — useful to be able to tweak those programmatically too, but the SEO fields are the priority.

## Why this isn't already supported (presumably)

`params` is a free-form JSON blob whose schema depends on the menu item's `type` and the component it points at. A blanket `set_params(params: object)` could clobber view-specific config the human had carefully set in the admin UI. Need a safer approach.

## Proposed API

Three options, pick one (or combine):

### Option A — Named SEO params (safest, least flexible)

Add explicit top-level args for just the SEO-relevant keys, applied via merge into the existing `params` blob:

```python
update_menu_item(
    id=101,
    browser_page_title="Westshore Eye Care — Optometrist in Colwood, BC",
    meta_description="Comprehensive eye exams, contact lens fittings...",
    meta_keywords=None,                  # null = leave alone
    robots="index,follow",
    page_heading=None,
    show_page_heading=None,
    page_class_sfx=None,
    secure=None,
)
```

Maps internally to:
```php
$params['page_title']             = $args->browser_page_title;
$params['menu-meta_description']  = $args->meta_description;
$params['robots']                 = $args->robots;
// ...etc, only for non-null args
```

**Pros:** can't break view-specific params; agent-friendly named fields; matches the admin UI labels.  
**Cons:** any future SEO param needs a code change.

### Option B — `params_set: object` for arbitrary key merging

```python
update_menu_item(
    id=101,
    params_set={
        "page_title": "Westshore Eye Care — Optometrist in Colwood, BC",
        "menu-meta_description": "...",
        "robots": "index,follow",
    }
)
```

Merges into the existing `params` blob. The agent has to know the right Joomla key names (`menu-meta_description`, not `meta_description`), but anything in `params` becomes reachable.

**Pros:** future-proof, no code change for new keys.  
**Cons:** agent can shoot itself in the foot by writing the wrong key name; silently writes garbage keys.

### Option C — Both (recommended)

Add Option A's named args **and** Option B's `params_set` escape hatch. Named args take precedence on collision. Mirrors the `value` / `value_object` pattern already used in `set_4seo_config`.

Also add a `params_unset: string[]` to delete keys (matches "set to default" in admin UI — different from setting to empty string).

## Safety considerations for either option

1. **Merge, don't replace.** The agent supplies the keys it wants changed; everything else stays. The current admin UI behavior on Save is "all params in the form get written"; we're doing better than that — only touch what was passed.

2. **Validate `robots` values.** Joomla's robots field is a select: `""` (use global), `"index,follow"`, `"noindex,follow"`, `"index,nofollow"`, `"noindex,nofollow"`. Refuse other values with a clear error.

3. **`browserNav` is already on the top-level args** — leave it there, don't move it into params territory.

4. **No `page_title` on items where it makes no sense?** Probably overthinking. Joomla allows `page_title` on any menu item; let the agent set it on any.

## Test cases that should pass once this lands

1. Set `browser_page_title` on home menu item (id=101 on westshoreeyecare.ca); fetch `/` and confirm `<title>` reflects the new value.
2. Set `meta_description` on a content article menu item; fetch the article page and confirm `<meta name="description">` matches (and the article's own `metadesc` is overridden, since menu params win).
3. Set `robots = "noindex,nofollow"` on a staging menu item; fetch and confirm the meta robots tag changed.
4. Set a `params_set` blob with mixed SEO + view-specific keys on a blog menu (`page_title` + `num_intro_articles`) and confirm both took effect without trampling other params.
5. Call `update_menu_item` with no params-touching args at all and confirm the existing `params` blob is byte-identical afterward.
6. Set a value, then immediately call `get_menu_item` and confirm round-trip.

## Files likely involved

- The class implementing `update_menu_item` (probably a `MenuItemController` or similar in cs-mcp-for-j)
- Look for where `Table::bind()` / `save()` is called and add params-merge logic before save
- `Joomla\Registry\Registry` is the right tool for merge semantics — read existing params into a Registry, `set()` each new key, write back via `toString()`

## Discovered on

Joomla 6.1.0, cs-mcp-for-j 1.7.4, during westshoreeyecare.ca SEO audit session 2026-05-12. Same session that surfaced the 4SEO use-flags issue (#1) — both blocked the home page meta-override task, but for different reasons.
