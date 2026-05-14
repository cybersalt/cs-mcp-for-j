# `plg_system_schemaorg` site-wide Organization profile — `set_plugin_params` writes don't take effect, AND setting `baseType` regresses per-article schema rendering

## TL;DR

Two related findings about the Joomla 6 core `plg_system_schemaorg` plugin:

1. **Flat `Organization_*` param keys don't render.** Writing `Organization_name`, `Organization_telephone`, `Organization_streetAddress`, etc. through `set_plugin_params(folder="system", element="schemaorg", params={...}, allow_locked=true)` succeeds at the DB level (the params persist) but **no Organization fields appear in the rendered JSON-LD on any page**. The Organization node still pulls only `name` and `url` from Joomla's global site config.

2. **Setting `baseType: "Organization"` actively breaks per-article schema.** When I added `baseType: "Organization"` to the schemaorg plugin params alongside the flat field keys, the entire `plg_system_schemaorg` JSON-LD `@graph` block — including the per-article `Article` / `Person` / `Physician` nodes we'd just set via `set_article_custom_jsonld` — **stopped emitting on the live pages**. Removing `baseType` (via `set_plugin_params` with `mode=replace` and empty params) restored per-article rendering immediately.

Net effect: there's currently **no way to populate a site-wide Organization / LocalBusiness JSON-LD profile through this MCP server**, and naive attempts to do so via the existing generic `set_plugin_params` tool can take down the per-article schema work the same agent just did.

This is the missing piece that prevented Westshore Eye Care from getting a fully-populated `LocalBusiness` rich result on Google. The per-article work (Dr. Schaafsma as `[Person, Physician]`, services as `MedicalBusiness` with `hasOfferCatalog`, etc.) all landed cleanly. The site-wide LocalBusiness — the one that should anchor the entire knowledge graph at `https://westshoreeyecare.ca/#optician` — couldn't be set programmatically.

## Repro for finding #1 (silent no-op)

```python
set_plugin_params(
    folder="system",
    element="schemaorg",
    allow_locked=True,
    params={
        "Organization_name": "Westshore Eye Care",
        "Organization_telephone": "+1-250-391-9311",
        "Organization_streetAddress": "100B - 2244 Sooke Road",
        "Organization_addressLocality": "Victoria",
        "Organization_addressRegion": "BC",
        "Organization_postalCode": "V9B 1X1",
        "Organization_addressCountry": "CA",
        "Organization_latitude": "48.435034",
        "Organization_longitude": "-123.494275",
        "Organization_email": "office@westshoreeyecare.ca",
        "Organization_logo": "https://westshoreeyecare.ca/images/logo.png",
    },
)
```

Then:

```python
get_plugin_params(folder="system", element="schemaorg")
```

Returns all the keys, persisted correctly. But fetch any page on the site and the rendered Organization node still looks like:

```json
{
  "@type": "Organization",
  "@id": "https://westshoreeyecare.ca/#/schema/Organization/base",
  "name": "Westshore EyeCare",
  "url": "https://westshoreeyecare.ca/"
}
```

— just the global-config defaults. None of the `Organization_*` keys made it into the JSON-LD. So **the plugin clearly uses a different key naming convention** than `{Type}_{field}` flat keys.

## Repro for finding #2 (regression)

Same call as above, but **add** `"baseType": "Organization"` to the params dict. Clear cache, fetch `https://westshoreeyecare.ca/meet-our-team/dr-david-schaafsma`. Before this call the page had three JSON-LD blocks including the per-article `[Person, Physician]` we'd set. After this call: **only 4SEO's auto-Article block and BreadcrumbList remain** — the entire `plg_system_schemaorg` `@graph` (Organization + WebSite + WebPage + Person/Physician) is gone.

Revert via:
```python
set_plugin_params(folder="system", element="schemaorg", params={}, mode="replace", allow_locked=True)
```

Per-article schema renders again on the next page load.

My read: setting `baseType` in the plugin params puts the plugin into some "site-wide Organization mode" that short-circuits the per-content-item schema processing. But this is speculation — I'd need to read the plugin source to be sure.

## What the right param structure probably is

I don't have a confirmed answer. Candidates worth checking against the Joomla 6 source (`plugins/system/schemaorg/`):

- **Nested object**: `{"baseType": "Organization", "Organization": {"name": "...", "telephone": "...", "address": {"streetAddress": "...", ...}}}`
- **Different prefix or no prefix at all**: maybe the plugin reads flat `name`, `telephone`, etc. and ignores the `Organization_` prefix
- **Subform / `addressInfo` style**: Joomla's recent form fields use subforms with their own key namespaces — could be a single `address` key holding a serialised subform
- **`SchemaorgPrepareDataEvent` / plugin event hook**: maybe the plugin doesn't read its own params for site-wide schema at all — it might only act on per-content-item schemas, and the site-wide Organization comes from somewhere else entirely (global config? a separate component?)

The fact that **even with seemingly-correct flat keys, the live render only uses `name` (from `sitename`) and `url` (from `Uri::root()`)** strongly suggests the plugin is using global config, not its own params, for the site-wide Organization. The plugin's own params may purely control _the dropdown of available schema types per article_, with `baseType` being a default for new articles. If that's true, **the right "site-wide LocalBusiness" path is probably 4SEO's Business Profile, not the Joomla core plugin** — and this issue should pivot to wrapping 4SEO's profile config instead.

Whoever picks this up should start by reading `plugins/system/schemaorg/schemaorg.php` (and the form XML in the same folder) before designing the tool.

## Proposed API once the right shape is known

A typed wrapper that knows the correct param structure, named to match the actual semantics:

```python
set_schemaorg_organization(
    name="Westshore Eye Care",
    alternate_name=None,
    description="Family optometry practice in Colwood, BC...",
    url="https://westshoreeyecare.ca/",
    logo="https://westshoreeyecare.ca/images/logo.png",
    image=None,
    telephone="+1-250-391-9311",
    email="office@westshoreeyecare.ca",
    address={
        "streetAddress": "100B - 2244 Sooke Road",
        "addressLocality": "Victoria",
        "addressRegion": "BC",
        "postalCode": "V9B 1X1",
        "addressCountry": "CA",
    },
    geo={"latitude": "48.435034", "longitude": "-123.494275"},
    sameAs=["https://www.facebook.com/...", "https://www.google.com/maps/place/..."],
    schema_type="Optician",   # Organization | LocalBusiness | Optician | MedicalBusiness | Physician | ...
    medical_specialty="Optometric",  # only when schema_type implies MedicalBusiness
)
```

The tool encapsulates the actual `params` key naming so the agent doesn't have to know. Companion `get_schemaorg_organization()` and `clear_schemaorg_organization()` round out the trio.

## Why this is worth wrapping

The unknown param structure is exactly the kind of "agent does the wrong thing because the schema is undocumented" trap the typed-wrapper pattern exists to prevent. We already have this pattern proven out for 4SEO meta overrides (`set_4seo_meta_override` wraps the three-layer envelope) and config (`set_4seo_config` wraps the size-based column routing). A `set_schemaorg_organization` would do the same for Joomla core's structured-data plugin.

And once it's working, every Cybersalt client site can get a proper LocalBusiness/Organization knowledge-graph anchor with one MCP call.

## Safety considerations

- The plugin is in the locked-plugins list (hence the existing `allow_locked` requirement). Whatever wrapper we build should still honour that — agents shouldn't be able to mass-rewrite schemaorg params without explicit intent.
- **Whatever the right structure is, the tool should refuse to set `baseType` (or whatever the equivalent is) in a way that disables per-content-item schema processing.** That regression is dangerous — silently nukes everything `set_article_custom_jsonld` and `set_article_schema` did. If the right "site-wide Organization" path requires that mode, the tool needs a big warning and ideally a roundtrip-verify step that re-fetches a known article page after the change and confirms its schema still renders.

## 4SEO alternative path — possibly a separate issue

4SEO has its own Business Profile (Components → 4SEO → Business Profile) that emits a site-wide `LocalBusiness` with telephone/address/geo/hours/logo. We saw the empty skeleton on the home page (`{"@type":"LocalBusiness","name":"Westshore EyeCare","telephone":"","address":{"@id":"#defaultAddress"},...}`) — it's clearly in 4SEO Free, just unconfigured. The Business Profile config is stored somewhere in `#__forseo_config` under a key we haven't enumerated yet (only `pages`, `system`, `sitemaps` keys exist by default — the Business Profile key only gets created when the human visits that page in admin).

**Could be a related (but separate) issue:** add a `set_4seo_business_profile()` typed wrapper. Would solve the "site-wide LocalBusiness on every page" problem without needing to crack the Joomla core schemaorg plugin. Probably the higher-ROI path of the two.

Filing this issue against the Joomla schemaorg plugin specifically because that's the path I tried and broke; the 4SEO Business Profile wrapper is worth a separate ticket once we have a Business Profile to inspect (i.e., once a human fills one in on any client site, dump the resulting config row and design from there).

## Discovered on

Joomla 6.1.0, plg_system_schemaorg core, cs-mcp-for-j 1.7.4, during westshoreeyecare.ca SEO audit session 2026-05-12. Same session as issues #1 (4SEO use-flags) and #2 (menu item params). All three blocked different parts of the same goal: getting Westshore Eye Care to a clean site-wide + per-page schema state through MCP alone, with no admin-UI clicks needed.
