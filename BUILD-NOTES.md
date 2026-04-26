# Build Notes — cs-mcp-for-j

Internal development log. **Not shipped in releases.** Records the references used to build each feature, as proof of independent development if the AGPL/GPL licensing decision is ever questioned.

## Licensing context

- This extension is GPL-2.0-or-later.
- Nicholas Dionysopoulos's MCP4Joomla is AGPL-3.0. AGPL is incompatible with both GPL-2.0-only and the JED submission rules.
- Decision (2026-04-24): clean-room implementation. Do not read MCP4Joomla PHP source while writing tool implementations.
- Allowed reference material: the MCP specification, Joomla core source/documentation, and the cs-template-integrity component for the Joomla Web Services API patterns Cybersalt has already used.

## Reference inventory

| Subsystem | Sources consulted (allowed) | Sources NOT consulted (forbidden) |
|---|---|---|
| Joomla Web Services API patterns | `JOOMLA5-WEB-SERVICES-API-GUIDE.md` (Joomla Brain), `cs-template-integrity` repo | — |
| MCP JSON-RPC wire protocol | MCP spec at modelcontextprotocol.io | MCP4Joomla server.php |
| MCP `tools/list` / `tools/call` shape | MCP spec | joomla-mcp-php tool implementations |
| Joomla content article create/update | Joomla `com_content` Administrator Article model (core source); JOOMLA5-CHECKLIST.md | MCP4Joomla article tools |
| Joomla 5 component + plugin packaging | Joomla Brain checklists; cs-template-integrity manifests | — |
| Authorization: Bearer translation | MCP spec auth section; Joomla `plg_api-authentication_token` | — |

## Per-feature provenance

### Component + Web Services + System plugin layout
- Pattern lifted from cs-template-integrity (own work).
- Plugin manifests use `<folder plugin="csmcpforj">services</folder>` per JOOMLA5-WEB-SERVICES-API-GUIDE.md naming rules.

### MCP Server (Server.php)
- JSON-RPC 2.0 framing (request, response, batch, notification, error codes -32700 / -32600 / -32601 / -32602 / -32603) is the JSON-RPC 2.0 spec, not MCP-specific.
- Server-defined error codes -32001 (auth required) and -32002 (forbidden) chosen from the JSON-RPC server-defined range -32000..-32099.
- Method set (`initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`) is the minimum required by the MCP spec for a tools-only server.
- `protocolVersion` echo-back behaviour: server accepts whatever ISO date the client sent in `initialize.params.protocolVersion`, falling back to `2025-06-18` as the v1 default.

### Article tools
- All article writes go through `com_content` Administrator Article model (`bootComponent('com_content')->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true])`) so asset, workflow, frontpage, and custom-fields side effects are correct.
- Field list (title, alias, catid, articletext, state, language, access, metadesc, metakey, images, featured) is the minimum subset of the `#__content` table that the Joomla Article admin form exposes.
- `list_categories` reads `#__categories` directly with `extension = 'com_content'`.

## Things to watch for in review

- If anyone copy-pastes from MCP4Joomla into this repo by accident, this file should be updated and the offending code removed.
- When adding new tool sets (menus, modules, users, etc.), add a row to "Per-feature provenance" first, then implement.

## Permission gating — deliberate design

The standard Cybersalt security baseline (NEW-EXTENSION-CHECKLIST.md) says "permission gate at the top of every controller method." This component has only one controller method (`McpController::handle`), and it deliberately does **not** gate at the top. Gating happens one layer deeper, inside `Server.php`, on a per-method basis:

| MCP method | Auth required | ACL required |
|---|---|---|
| `initialize` | Joomla API token (HTTP layer) | none |
| `notifications/*` | Joomla API token | none |
| `ping` | Joomla API token | none |
| `tools/list` | Joomla API token | `csmcpforj.use` (or `core.manage`) |
| `tools/call` (read tool) | Joomla API token | `csmcpforj.use` |
| `tools/call` (write tool) | Joomla API token | `csmcpforj.write` |

Reasoning: the MCP spec requires `initialize` to be reachable without app-level authorisation so clients can negotiate the protocol version before knowing what they can actually do. A blanket controller-level gate would block the handshake. A guest never reaches the controller in the first place because Joomla's `plg_api-authentication_token` rejects requests without a valid `X-Joomla-Token` (or translated Bearer token) at the HTTP layer.

If a future change adds a non-MCP-spec endpoint or a long-lived session token, revisit this and consider promoting the gate to controller level.

## Joomla Brain conformance audit (2026-04-25)

Verified against NEW-EXTENSION-CHECKLIST.md, JOOMLA5-CHECKLIST.md, JOOMLA5-PLUGIN-GUIDE.md, JOOMLA5-WEB-SERVICES-API-GUIDE.md, and PACKAGE-BUILD-NOTES.md. Conforming items not listed.

Fixes applied during audit:
- Component and plugin manifest `<name>` switched from raw element strings to language constants (`COM_CSMCPFORJ`, `PLG_SYSTEM_CSMCPFORJ`, `PLG_WEBSERVICES_CSMCPFORJ`).
- `script.php` now HTML-escapes every `Text::_()` output, calls `clearAutoloadCache()` first, gates side effects on `$type ∈ {install, update, discover_install}`.
- Added `build.ps1` producing `pkg_csmcpforj_v{version}_{yyyymmdd}_{hhmm}.zip` via 7-Zip per Brain naming convention.

Deferred until release prep:
- `CHANGELOG.html` (article-ready) — required for the update-server changelog feed.
- `updates.xml` + `<updateservers>` in pkg manifest — needs a GitHub release URL to point at.
- `<sha256>` in `updates.xml` — generated from the built zip per release.
- Joomla Brain submodule — added at `git init` time so it doesn't sit in working dir before the repo exists.
- Security-review skill run before tagging v1.0.0.
