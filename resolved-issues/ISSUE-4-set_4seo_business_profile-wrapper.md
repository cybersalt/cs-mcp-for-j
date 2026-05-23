# Add a typed `set_4seo_business_profile` wrapper — the working site-wide LocalBusiness path for sites where `plg_system_schemaorg` is unavailable or broken

## Why this is needed

4SEO emits a site-wide `LocalBusiness` JSON-LD node on every page, independent of Joomla core's `plg_system_schemaorg`. On a freshly-configured site that node is an **empty skeleton** — 4SEO knows it should emit a LocalBusiness but has no business data to fill it.

Observed live on **westshoreeyecare.ca** home page:

```json
{
  "@type": "LocalBusiness",
  "@id": "https://westshoreeyecare.ca/#defaultBusiness",
  "name": "Westshore EyeCare",
  "url": "https://westshoreeyecare.ca/",
  "telephone": "",
  "address": { "@id": "https://westshoreeyecare.ca/#defaultAddress" },
  "geo": { "@id": "https://westshoreeyecare.ca/#defaultGeo" },
  "image": { "@id": "https://westshoreeyecare.ca/#defaultLogo" }
}
```

Empty `telephone`, dangling `@id` references to `#defaultAddress` / `#defaultGeo` / `#defaultLogo` that resolve to nothing. 4SEO's dashboard surfaces this as the `dashboard.warningSdMissingLogo` and `dashboard.profilePagePrompt` warnings. This is the **4SEO Free** build — the Business Profile feature is present, just unconfigured.

Filling in 4SEO's Business Profile form (Components → 4SEO → Business Profile) populates all of that — `telephone`, `PostalAddress`, `GeoCoordinates`, logo `ImageObject`, opening hours — and turns the skeleton into a valid LocalBusiness rich result.

**This matters specifically because of issue #3:** on westshoreeyecare.ca, the Joomla core `plg_system_schemaorg` path to a site-wide LocalBusiness is **broken** — setting `baseType` to anything kills all plugin schema output site-wide (see ISSUE-3). So for Westshore, and any other site with the same `plg_system_schemaorg` failure mode, **4SEO's Business Profile is the only working path to a site-wide LocalBusiness**. There is currently no MCP tool to set it.

## The chicken-and-egg problem

The Business Profile config is stored in `#__forseo_config`. But on a default site, that table only has three keys — `pages`, `system`, `sitemaps`. The Business Profile key **does not exist until a human visits and saves the Business Profile form in the 4SEO admin UI at least once.**

Confirmed by checking two live sites via `query_4seo_table(table="forseo_config")`:
- **westshoreeyecare.ca** — keys: `pages`, `system`, `sitemaps`. No business profile key.
- **goatsatwork.ca** — keys: `pages`, `system`. No business profile key (goatsatwork solved its site-wide schema via `plg_system_schemaorg` instead, so it never needed 4SEO's profile).

So we can't reverse-engineer the structure from either site we currently have MCP access to — neither has ever had the form filled in.

## Investigation steps (do these first)

1. On any client site with 4SEO installed, fill in the Business Profile form in the admin UI completely — name, type, phone, address, geo, logo, hours, the lot. Save it.
2. `query_4seo_table(table="forseo_config")` and find the new key (likely something like `businessProfile`, `profile`, `sd`, or `localBusiness`).
3. Dump that row's `value` (and note its `format` — 1=raw string, 2=JSON, per the `#__forseo_config` column convention from v1.7.1). Capture the exact JSON shape: how `address` is nested, how `geo` is represented, how opening hours are structured, how the logo is referenced, what the `type` enum values are (LocalBusiness / Optician / MedicalBusiness / etc.).
4. Cross-check against 4SEO v6.12.0's source (available locally per the v1.7.0 changelog note) — find the class that reads this config key and renders the `#defaultBusiness` node, to confirm field names and required vs optional.

## Proposed API (shape pending investigation)

```python
set_4seo_business_profile(
    business_type="Optician",            # LocalBusiness | Optician | MedicalBusiness | ...
    name="Westshore Eye Care",
    telephone="250-391-9311",
    fax="250-391-9708",
    email="office@westshoreeyecare.ca",
    url="https://westshoreeyecare.ca/",
    logo="https://westshoreeyecare.ca/images/Westshore-Eye-Care-Logo-Colour.png",
    price_range="$$",
    address={
        "streetAddress": "100B - 2244 Sooke Road",
        "addressLocality": "Victoria",
        "addressRegion": "BC",
        "postalCode": "V9B 1X1",
        "addressCountry": "CA",
    },
    latitude=48.435034,
    longitude=-123.494275,
    opening_hours=[
        {"days": ["Tuesday"],   "opens": "08:00", "closes": "12:00"},
        {"days": ["Tuesday"],   "opens": "13:00", "closes": "17:00"},
        {"days": ["Wednesday"], "opens": "09:00", "closes": "12:00"},
        {"days": ["Wednesday"], "opens": "13:00", "closes": "17:00"},
        {"days": ["Thursday"],  "opens": "11:00", "closes": "16:00"},
        {"days": ["Thursday"],  "opens": "17:00", "closes": "20:00"},
        {"days": ["Friday"],    "opens": "09:00", "closes": "13:00"},
        {"days": ["Friday"],    "opens": "14:00", "closes": "17:00"},
        {"days": ["Saturday"],  "opens": "09:00", "closes": "13:00"},
    ],
    social_media=["https://www.facebook.com/..."],
)
```

Companion `get_4seo_business_profile()` and `clear_4seo_business_profile()`.

What the wrapper handles for the agent:

1. **Creates the config key if it doesn't exist yet** — solves the chicken-and-egg. The agent shouldn't need a human to click into the admin UI first; the tool should be able to write the `forseo_config` row from scratch with the correct `key`, `scope` (`default`), and `format` (`2` = JSON).
2. **Encodes the structure** — nested `address`, however 4SEO represents geo and hours, the logo reference format — so the agent passes flat, readable args.
3. **Validates `business_type`** against 4SEO's accepted type list.
4. **Clears 4SEO's cache** after writing so the `#defaultBusiness` node repopulates on next render.
5. **Roundtrip-verify** (same pattern proposed for `set_schemaorg_organization` in ISSUE-3) — re-fetch the home page, confirm the `LocalBusiness` node now has a non-empty `telephone` and a resolving `address`, return the before/after so the agent knows it actually landed.

## Relationship to the existing 4SEO tools

This sits alongside `set_4seo_config` and `set_4seo_meta_override` as the third typed 4SEO write wrapper. The generic `query_4seo_table` / `update_4seo_row` escape hatches can technically reach the business profile row once it exists — but, like the meta-override envelope and the config column-format routing, the structure is non-obvious enough to deserve a typed front door. And the "create the key from nothing" step is something the generic tools can do but the agent would never know to do without reading 4SEO source.

## Priority note

For **westshoreeyecare.ca specifically**, this is currently the **only** known working path to a site-wide LocalBusiness rich result — the `plg_system_schemaorg` path is broken on that site (ISSUE-3). The per-article schema there is already done and rendering; the site-wide business node is the one remaining structured-data gap. So this isn't purely speculative tooling — there's a live client site waiting on it.

## Discovered on

westshoreeyecare.ca (4SEO 6.10.1.2660 Free) and goatsatwork.ca (4SEO 6.11.0) — neither has a Business Profile filled in, so the config structure is currently unknown and needs the investigation steps above before the wrapper can be built. cs-mcp-for-j 1.7.4. Same audit session as issues #1, #2, #3 — 2026-05-12.
