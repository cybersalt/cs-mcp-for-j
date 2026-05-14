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

Fetching the live page shows Joomla's original `<title>` and 4SEO's auto-generated description, not the custom values.

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

## Important caveat for the test plan

Even with all flags correctly flipped on the Westshore Eye Care site, the override **still didn't render** at request time. This appears to be a separate issue — likely that **4SEO Free** doesn't apply per-page custom meta at all (it may be a Pro-only feature; the system config has no `dlid` set). Worth verifying this fix on a 4SEO Pro–licensed site before declaring the issue fully closed. If per-page meta turns out to be Pro-only, the tool should ideally detect a missing `dlid` and either warn the caller or refuse the call with a clear error.

## Files likely involved

- Whatever PHP class implements `set_4seo_meta_override` (probably a method on a `MetaOverrideController` or similar in `cs-mcp-for-j` source)
- Look for where `$data['custom']['title']` etc. are being set and add the matching `$data['useTitle'] = 1` line right after, gated on whether the corresponding arg was supplied

## Discovered on

Joomla 6.1.0, 4SEO 6.10.1.2660 Free, cs-mcp-for-j 1.7.4, during westshoreeyecare.ca SEO audit session 2026-05-12.
