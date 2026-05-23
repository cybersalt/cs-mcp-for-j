# Add a typed `set_schemaorg_organization` wrapper for `plg_system_schemaorg` — the correct param structure is non-obvious, and setting `baseType` can silently kill all schema output on some sites

## Status correction

An earlier draft of this issue framed `set_plugin_params` as buggy for not applying `Organization_*` keys. **That was wrong** — `set_plugin_params` works correctly; the agent (me) supplied the wrong param structure. This issue is now a **feature request** for a typed wrapper, plus a **real diagnostic finding** about a site-specific failure mode that the wrapper should defend against.

## The confirmed-correct param structure

Joomla 6 core `plg_system_schemaorg` does **not** use flat `{Type}_{field}` keys. The working structure — pulled live from goatsatwork.ca, which renders a valid LocalBusiness that passes Google's Rich Results Test — is:

```json
{
  "baseType": "localBusiness",
  "name": "Goats at Work",
  "alternateName": "Goats At Work Ltd",
  "url": "https://www.goatsatwork.ca/",
  "image": "https://www.goatsatwork.ca/images/.../goats-at-work-234x234-tp.png",
  "telephone": "+1-705-345-4484",
  "email": "dan@goatsatwork.ca",
  "priceRange": "$$",
  "socialmedia": [
    { "url": "https://www.instagram.com/goats_at_work_/" },
    { "url": "https://www.facebook.com/profile.php?id=61572101199445" }
  ],
  "address": {
    "streetAddress": "747 Moonstone Rd E",
    "addressLocality": "Coldwater",
    "addressRegion": "ON",
    "postalCode": "L0K 1E0",
    "addressCountry": "CA"
  },
  "latitude": 44.6924,
  "longitude": -79.6594
}
```

Key facts about the structure, none of which are discoverable without reading the plugin or an existing working site:

- **`baseType`** is camelCase: `localBusiness` (also `organization`, `person`, etc. — Joomla's internal type slugs, **not** the schema.org PascalCase type names)
- Fields are **flat at the top level** — `name`, `telephone`, `email`, `url`, `image`, `alternateName`, `priceRange` — **no `Organization_` prefix**
- **`address`** is a **nested object** with `streetAddress` / `addressLocality` / `addressRegion` / `postalCode` / `addressCountry`
- **`socialmedia`** is an **array of `{ "url": "..." }` objects** (this is what becomes `sameAs` in the output)
- **`latitude`** and **`longitude`** are **top-level numbers** — not nested under a `geo` object, not strings

## The site-specific failure mode (the part that needs defending against)

On **goatsatwork.ca**, the structure above works perfectly — `baseType: localBusiness` produces a full `@graph` (LocalBusiness + PostalAddress + GeoCoordinates + ImageObject + WebSite + Organization).

On **westshoreeyecare.ca**, setting `baseType` to *anything* — even the absolute minimal `{ "baseType": "localBusiness", "name": "...", "url": "..." }` — causes `plg_system_schemaorg` to emit **nothing at all**. The entire plugin `@graph` block disappears from every page, *including* the per-article `Article` / `Person` / `Physician` nodes that were rendering fine moments before. Clearing `baseType` (empty params) immediately restores full output.

Both sites:
- Run the **core** `plg_system_schemaorg` (not a third-party schema plugin)
- Have the plugin **enabled** and **locked**
- Are on Joomla 6

So something environment-specific on the Westshore site — a plugin/Joomla point-version difference, a PHP-level exception in the schema compile path swallowed by Joomla's error handling, a template (`westshore-eye-care` custom template) interfering, or a conflicting extension — makes `baseType` fatal there. Couldn't pin it down from the MCP layer alone; would need the Joomla error log or the plugin source on that server.

**The danger:** an agent following the "correct" recipe to set up a site-wide LocalBusiness can, on an affected site, silently destroy all the per-article schema work it just did in the same session — with no error returned. `set_plugin_params` returns `ok: true`; the params are stored correctly; the live pages just quietly lose their structured data.

## Proposed: `set_schemaorg_organization` (typed wrapper, with verification)

```python
set_schemaorg_organization(
    base_type="localBusiness",          # localBusiness | organization | person | ...
    name="Westshore Eye Care",
    alternate_name="Westshore Optometric Eye Care",
    url="https://westshoreeyecare.ca/",
    image="https://westshoreeyecare.ca/images/Westshore-Eye-Care-Logo-Colour.png",
    telephone="+1-250-391-9311",
    email="office@westshoreeyecare.ca",
    price_range="$$",
    social_media=[
        "https://www.facebook.com/...",
    ],
    address={
        "streetAddress": "100B - 2244 Sooke Road",
        "addressLocality": "Victoria",
        "addressRegion": "BC",
        "postalCode": "V9B 1X1",
        "addressCountry": "CA",
    },
    latitude=48.435034,
    longitude=-123.494275,
    verify_url="/",                     # optional: page to re-fetch after the change
)
```

What the wrapper does:

1. **Encodes the structure** — agent passes a flat `social_media` list of URL strings; tool wraps each into `{ "url": ... }`. Agent passes `latitude`/`longitude` as numbers; tool ensures they're stored as numbers, not strings. Agent never has to know `baseType` is camelCase or that `address` is nested.
2. **Validates `base_type`** against Joomla's known type slugs; rejects PascalCase schema.org names with a helpful "did you mean `localBusiness`?" error.
3. **Roundtrip-verifies** (the important part): before the change, fetch `verify_url` and count `application/ld+json` blocks + note whether a `plg_system_schemaorg` graph is present. Apply the change. Re-fetch. **If the plugin's schema output disappeared, automatically revert the params and return an error** explaining that `baseType` breaks schema rendering on this site and the change was rolled back. This turns the silent-destruction failure mode into a safe, loud, self-healing one.
4. Companion `get_schemaorg_organization()` and `clear_schemaorg_organization()`.

The roundtrip-verify pattern could also be a generic option on `set_plugin_params` (`verify_url=...`, `verify_jsonld=true`) since "did this plugin change silently break the page" is a broader risk than just schemaorg.

## Why wrap it

This is exactly the failure the typed-wrapper pattern exists to prevent — same rationale as `set_4seo_meta_override` wrapping the three-layer envelope and `set_4seo_config` wrapping the column-format routing. The schemaorg param structure is undiscoverable, the camelCase `baseType` is a trap, and the silent-breakage failure mode on some sites makes a naive `set_plugin_params` call genuinely dangerous. A wrapper that encodes the structure *and* verifies the result would make site-wide schema a safe one-call operation across all Cybersalt client sites.

## Related — possibly higher ROI: `set_4seo_business_profile`

4SEO has its own Business Profile (Components → 4SEO → Business Profile) that emits a site-wide `LocalBusiness` independent of `plg_system_schemaorg`. On westshoreeyecare.ca the empty skeleton is already visible on the home page (`{"@type":"LocalBusiness","name":"Westshore EyeCare","telephone":"","address":{"@id":"#defaultAddress"},...}`) — it's in 4SEO Free, just unconfigured. The profile config lands in `#__forseo_config` under a key that only gets created once a human fills in the admin form (default sites only have `pages` / `system` / `sitemaps` keys).

**Suggested companion ticket:** once any client site has a filled-in 4SEO Business Profile, dump that `forseo_config` row, learn the key + structure, and build a `set_4seo_business_profile` typed wrapper. On sites where `plg_system_schemaorg` + `baseType` is broken (like Westshore), this would be the working path to a site-wide LocalBusiness.

## Discovered on

- **Working reference:** goatsatwork.ca — `plg_system_schemaorg` with `baseType: localBusiness`, full structure, renders + passes Google Rich Results Test.
- **Broken case:** westshoreeyecare.ca, Joomla 6.1.0, PHP 8.4.21, cs-mcp-for-j 1.7.4 — setting `baseType` to anything kills all `plg_system_schemaorg` output site-wide. Reverted to empty params; per-article schema (set via `set_article_custom_jsonld`) is intact and rendering. Site-wide LocalBusiness on Westshore remains unsolved pending either a server-side investigation of why `baseType` is fatal there, or a `set_4seo_business_profile` path.
- Same session as issues #1 (4SEO use-flags) and #2 (menu item params), 2026-05-12.
