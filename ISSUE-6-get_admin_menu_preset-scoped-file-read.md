# `get_admin_menu_preset` — scoped file-read tool for admin sidebar preset XMLs

## Summary

MCP-for-J currently has no way to inspect the XML files that actually draw the Joomla admin sidebar (System / Users / Menus / Content / Components / Extensions / Help and their sub-items). Every existing menu-related tool (`list_menus`, `list_menu_items`, `get_menu_item`, etc.) reads from `#__menu`, but in Joomla 4+ the admin sidebar is **not** driven from `#__menu` — it's rendered at request time from XML preset files on disk:

- `administrator/components/com_menus/presets/default.xml` — the master sidebar tree
- `administrator/components/com_menus/presets/system.xml`, `users.xml`, `menus.xml`, `components.xml`, `help.xml`, `alternate.xml` — sub-dashboards
- `administrator/components/com_content/presets/content.xml` — Content dashboard sub-items
- `administrator/components/com_users/presets/users.xml` — Users dashboard sub-items
- (Any component can drop its own `presets/*.xml`)

When a Super User reports "the Content > Fields menu item disappeared" or "the Users submenu is wrong on this install," the natural next step is to diff the on-disk XML against the stock Joomla version — but that requires reading a file, and today the answer is "SSH in and look" which defeats the purpose of the MCP.

## Real-world hit

americanfoam.com/stageit, 2026-07-06. Client site running Joomla 5.4.6. `#__menu` had all 20 seed rows intact, `com_fields` was enabled, every `plg_fields_*` was enabled — but Content > Fields, Content > Field Groups, Users > Fields, and Users > Field Groups were reportedly missing from the admin sidebar. Nothing exposed via the existing MCP-for-J tool surface could confirm whether the on-disk `administrator/components/com_menus/presets/default.xml` had been edited (attacker plant? sloppy template dev? third-party plugin's postflight?) or whether some `onPreprocessMenuItems` handler was stripping items at render time.

The session ended with "you'll need to grab that file over SSH so I can diff it against stock 5.4.6." A scoped read tool would have closed the loop inside the MCP session.

## Why not just add a general `read_file` tool

A general arbitrary-read tool would solve this, but the blast radius is enormous:

- A leaked API token today = attacker can create/edit content, install extensions. Bad, but bounded.
- A leaked token with `read_file` = attacker can pull `configuration.php` (DB password, `$secret`, mailer creds, session key). Full site compromise + potentially shared-credential lateral movement.
- Also grants silent, script-friendly recon over the whole webroot — much stealthier than the current "install a plugin to exfiltrate" path.
- JED review for the extension gets much harder.

A Super User can technically already read anything by installing a purpose-built plugin, but that route is loud (leaves install records + files). A `read_file` tool is silent. That asymmetry is the whole problem.

**Recommendation: don't add arbitrary `read_file`. Add narrowly scoped diagnostic readers, one file family at a time.** This issue covers the admin-menu-preset family.

## Proposed API

### Tool 1 — `list_admin_menu_presets`

Enumerate all preset XML files discoverable in the install. Read-only, no arguments.

```json
{
  "count": 9,
  "presets": [
    {
      "component": "com_menus",
      "name": "default",
      "path": "administrator/components/com_menus/presets/default.xml",
      "size": 8421,
      "mtime": "2026-05-26T15:12:03+00:00",
      "sha256": "…",
      "stock_sha256_j546": "…",
      "matches_stock": true
    },
    {
      "component": "com_menus",
      "name": "system",
      …
    },
    {
      "component": "com_content",
      "name": "content",
      …
    },
    …
  ]
}
```

The `matches_stock` boolean and `stock_sha256_j*` field are optional-but-really-valuable: if we bundle the stock hashes for the supported Joomla versions, the caller can spot a modified preset in a single call without pulling the full XML.

### Tool 2 — `get_admin_menu_preset`

Fetch the contents of one specific preset.

**Input (name-based — recommended):**
```json
{
  "component": "com_menus",   // required
  "name": "default"           // required — resolves to component/presets/<name>.xml
}
```

**NOT** a raw-path input. `component` is validated against `^com_[a-z0-9_]+$` and `name` against `^[a-z0-9_-]+$`. The tool builds the path itself:
`administrator/components/{component}/presets/{name}.xml`.

That eliminates path-traversal, symlink-escape, and "trick it into reading `configuration.php`" attacks by construction — the caller cannot express a path that isn't a preset XML.

**Output:**
```json
{
  "component": "com_menus",
  "name": "default",
  "path": "administrator/components/com_menus/presets/default.xml",
  "size": 8421,
  "mtime": "2026-05-26T15:12:03+00:00",
  "sha256": "…",
  "content": "<?xml version=\"1.0\"?>\n<menu …"
}
```

Text-only. Binary read is not needed for XML.

### Optional Tool 3 — `diagnose_admin_menu`

Higher-level convenience tool: reads every discoverable preset, diffs against bundled stock hashes for the running Joomla version, and returns a report of modified/added/removed presets and (for modified ones) which `<menuitem>` blocks are missing. Would have answered the americanfoam question in one call.

Nice-to-have; can land in a later release.

## Path-allowlist logic (this is the whole security model — get it right)

The read implementation must:

1. Take `component` and `name` as **structured inputs**, not a path.
2. Reject `component` values not matching `^com_[a-z0-9_]+$`.
3. Reject `name` values not matching `^[a-z0-9_-]+$`.
4. Build the path as `JPATH_ADMINISTRATOR . '/components/' . $component . '/presets/' . $name . '.xml'`.
5. `realpath()` the built path AND `realpath(JPATH_ADMINISTRATOR . '/components')`.
6. Confirm the resolved file path starts with the resolved base path (guards against symlink escape). If not — refuse.
7. Confirm the file exists, is a regular file (`is_file()`, not `is_link()` alone), and ends in `.xml`.
8. Read via `file_get_contents()` — no PHP execution, no include.
9. Cap response size at ~256 KB. If a preset is bigger than that, something is very wrong anyway.

Do **not** accept any of:
- Leading `/`, `~`, `\\`
- `..` anywhere in inputs
- `\0` bytes
- Path separators inside `component` or `name`

## What's explicitly out of scope for this tool

- `configuration.php` — never
- Anything under `JPATH_ROOT` that isn't under `administrator/components/*/presets/`
- Symlinks that resolve outside the allowlist base
- Any file not ending in `.xml`
- Any read that would require permission-escalation from the API-token user

If someone wants to read language files, template files, plugin XMLs, etc., those get their own scoped tools with their own allowlists — not this one.

## Test cases that should pass once this lands

1. `list_admin_menu_presets` on a stock Joomla 5.4.6 returns exactly the expected 9 presets, all with `matches_stock=true`.
2. `get_admin_menu_preset(component="com_menus", name="default")` on stock 5.4.6 returns byte-identical content to the shipped file.
3. Same call on the americanfoam.com install returns the actual on-disk content, and the caller can diff it against stock to identify the missing `<menuitem>` blocks for Content Fields / Users Fields.
4. `get_admin_menu_preset(component="../etc", name="passwd")` — refused with a validation error, no file access attempted.
5. `get_admin_menu_preset(component="com_menus", name="../../../configuration")` — refused (name regex rejects `../`).
6. `get_admin_menu_preset(component="com_menus", name="nonexistent")` — clean 404-shaped response, not a PHP warning.
7. Symlink `administrator/components/com_evil/presets/default.xml -> /etc/passwd` — read attempt refused because realpath escapes the allowlist base.
8. Preset file with a size of 10 MB — read refused (or truncated with a clear indicator) because of the size cap.

## Files likely involved

- New controller class alongside the existing menu-related controllers in `com_csmcpforj` (probably `Cybersalt\Component\Csmcpforj\Administrator\Mcp\MenuPresetController` or similar — match the existing pattern).
- Tool registration in whatever the current `tools/list` handler is (`ToolCatalog::register(...)` or a similar surface).
- Bundled stock-hash lookup table for `matches_stock` — a small PHP array keyed by `[joomlaVersion][component][name] => sha256`, seeded for the supported versions (5.4.x, 6.0.x, 6.1.x as of writing).

## Related follow-on issues (not this issue's scope)

- `get_language_file` — same shape, allowlisted to `administrator/language/**/*.ini` + `language/**/*.ini`. Diagnostic value: spotting bad language overrides.
- `get_template_manifest` — read `templateDetails.xml` for the currently active template, useful for confirming version + author. Already partially covered by `read_template_file` but that tool is scoped to a specific template folder.
- `diagnose_admin_menu` — the higher-level tool described above.
- `get_component_manifest` — read `<component>.xml` for any installed component. Would let the agent confirm installed version without hitting `#__extensions`.

Each of these should be a separate scoped tool with its own allowlist. **Do not merge them into a general `read_file`.**

## Discovered on

Joomla 5.4.6, cs-mcp-for-j 1.0.0 (current release on the site), during a "review the core admin menu items" audit session on americanfoam.com/stageit 2026-07-06. Same session established that the sidebar is preset-driven, not DB-driven — a fact worth calling out in the tool description so callers don't waste round-trips on `list_menu_items` when the answer they want is in an XML file.
