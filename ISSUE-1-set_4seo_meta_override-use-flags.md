# `set_4seo_meta_override` writes custom values but doesn't flip the `use*` flags inside `data`, so overrides never render

## Summary

`set_4seo_meta_override` correctly writes the supplied `title` / `description` / `canonical` / `robots` values into `data.custom.*` in the `#__forseo_custom_meta` row, and sets `status_title = 2` and `status_description = 2` (custom). However, it leaves the per-key `useTitle` / `useDescription` / `useCanonical` / `useRobots` / `useImage` flags inside the same `data` JSON blob at `0`. 4SEO's renderer checks those `use*` flags at request time and skips the override when they're `0`, so the override is silently inert even though every visible field says it should apply.

## Repro

```
set_4seo_meta_override(article_id=3, title="Custom T", description="Custom D")
```

Then:

```
query_4seo_table(table="forseo_custom_meta")
```

Returns a row where:

- `status_title = 2`, `status_description = 2` ✓
- `enabled = 1` ✓
- `data.custom.title = "Custom T"` ✓
- `data.custom.description = "Custom D"` ✓
- **`data.useTitle = 0`** ✗
- **`data.useDescription = 0`** ✗

Fetching the live page (once the page has been crawled — see the note below) shows Joomla's original `<title>` and 4SEO's auto-generated description, not the custom values.

## Expected

When the tool is given a value for a field, it should set the matching `use*` flag to `1` in the `data` blob:

| Arg supplied | Flag to set |
|---|---|
| `title` | `data.useTitle = 1` |
| `description` | `data.useDescription = 1` |
| `canonical` | `data.useCanonical = 1` |
| `robots` | `data.useRobots = 1` |
| `image` / `sharing_image` | `data.useImage = 1` |

If an arg is **not** supplied on a subsequent call, leave the existing `use*` flag alone — preserves partial-override semantics for tools that update one field at a time.

Optional: add a `clear` parameter (or matching `use_title=false` style flags) to explicitly reset a `use*` flag back to `0` without deleting the underlying custom value.

## Workaround used in the field

After calling `set_4seo_meta_override`, fetch the row with `query_4seo_table`, mutate `data` to set the appropriate `use*` flags to `1`, and write it back via:

```
update_4seo_row(table="forseo_custom_meta", pk_value=<row_id>, set={"data": "<new JSON>"})
```

Cumbersome and bypasses any future schema changes 4SEO might make to the `data` shape.

## Update — the "didn't render" caveat was a red herring

An earlier draft of this issue speculated that 4SEO Free might not apply per-page custom meta at all (a suspected Pro gate). **That was wrong.** Confirmed on westshoreeyecare.ca 2026-05-13: 4SEO Free *does* apply per-page custom title/description — but only once the page has been crawled into `#__forseo_pages`. During the original session the home page simply hadn't been crawled yet, so the override (correct flags and all) sat inert; once the crawler reached it, the custom title and description rendered correctly with no further intervention.

So the `use*`-flags bug above is the *whole* issue here — there is no separate Pro gate to worry about for basic per-page title/description meta. (4SEO's **rules engine** and **per-page raw content injection** *are* Pro-gated, but those are different features and unrelated to this bug.) No `dlid` detection is needed — the fix is simply to flip the flags.

One practical implication for the fix: because the override only takes visible effect after the page is in `#__forseo_pages`, a good `set_4seo_meta_override` could optionally note in its response whether the target URL is already crawled, so the caller knows whether to expect an immediate change or wait for the crawler.

## Files likely involved

- Whatever PHP class implements `set_4seo_meta_override` (probably a method on a `MetaOverrideController` or similar in `cs-mcp-for-j` source)
- Look for where `$data['custom']['title']` etc. are being set and add the matching `$data['useTitle'] = 1` line right after, gated on whether the corresponding arg was supplied

## Discovered on

Joomla 6.1.0, 4SEO 6.12.0.2692 (free build), cs-mcp-for-j 1.7.4. Originally found during the westshoreeyecare.ca SEO audit session 2026-05-12; the "didn't render" caveat corrected 2026-05-13 after confirming the crawl-state dependency on cs-mcp-for-j 1.7.5.
