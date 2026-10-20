# CS MCP for J (`cs-mcp-for-j`)

Turns a Joomla 5/6 site into its own [MCP](https://modelcontextprotocol.io) server, so an AI app can work on the site directly: articles, categories, menus, modules, users, custom fields, templates, extensions, Schema.org and more. It works with any MCP-capable app: ChatGPT, Claude (claude.ai, Desktop, Code), Codex, Cursor, VS Code + GitHub Copilot, Gemini CLI, Cline, Windsurf and others. The site itself is the server. There's no separate server process to run and no third-party proxy.

**Documentation:** <https://docs.cybersalt.com/extensions/mcp-for-j> · **Download:** <https://www.cybersalt.com/extensions/mcp-for-j>

> **Status:** v2.9.4. Since 2.9.0: optional OAuth sign-in (so ChatGPT and claude.ai can connect), an activity log, and Super-User-only gates on the tools that can change who has power over a site. Free and fully featured; optional add-ons extend it to specific third-party extensions.

> **Name:** this extension was called "Cybersalt MCP for Joomla" until 2.9.3. The Joomla trademark rules, which the Joomla Extensions Directory enforces, need a licence from Open Source Matters for any extension name containing "Joomla", so 2.9.3 renamed it "Cybersalt MCP for J". Since 2.9.4 its install name is **CS MCP for J**, matching its JED listing (the JED asks that the two match). The component is "MCP for J" in the Components menu. Only the name changed: the element (`pkg_csmcpforj`), the endpoint, settings, tokens and updates are all the same.

## What it ships

| Element | Type | Job |
|---|---|---|
| `com_csmcpforj` | Component | The JSON-RPC MCP server at `/api/index.php/v1/mcp`, the tool registry and permission checks, the OAuth server, the activity log, and the admin screens (Dashboard, Browse MCP Add-ons, Setup Guide, Logs, Support). |
| `plg_webservices_csmcpforj` | Web Services plugin | Registers the `/v1/mcp` API route. **Required**: without it the route returns 404. |
| `plg_system_csmcpforj` | System plugin | Registers the built-in tools and turns `Authorization: Bearer` into `X-Joomla-Token` for Joomla's token plugin. |
| `plg_api-authentication_csmcpforj` | API authentication plugin | Accepts MCP for J OAuth access tokens on the MCP route, only when OAuth sign-in is switched on. |

All four are in `pkg_csmcpforj` and are enabled automatically on install. **Requirements:** Joomla 5.0+ (native on Joomla 5 and 6), PHP 8.2+, MySQL or MariaDB for OAuth and the activity log.

## Connecting an AI app

Open **Components → MCP for J → Dashboard**. The *Connect an AI app to this site* card has three ways in:

1. **Log in (easiest).** Switch on **OAuth sign-in** in Options. Then paste the site's Endpoint URL into the AI app, log in with your normal Joomla username and password, and click **Allow**. This is the only way **ChatGPT** (Plugins → Add → Add custom MCP server; works on Plus) and **claude.ai / Claude Desktop** (Settings → Connectors → Add custom connector) can connect. Claude Code, Codex, Cursor, VS Code + Copilot, Gemini CLI and Cline can sign in the same way. Connected apps are listed on the Dashboard, where each can be disconnected.
2. **Paste a setup prompt**, for AI apps that can run commands (Codex, Claude Code, Cursor, Cline, Copilot agent mode). Paste your API token into the Dashboard, copy the prompt, and paste it into a new chat. The AI tests the connection and can offer to save it.
3. **Config file or command line.** Copy the ready-made configuration, which has your token filled in, into the app's settings. The **Setup Guide** has step-by-step instructions for each app.

### API tokens

Each Joomla user has their own token: **Users → (your profile) → Joomla API Token → Reset**, then click the eye icon to copy it. The endpoint accepts it as `Authorization: Bearer <token>` or as `X-Joomla-Token: <token>`. Use `X-Joomla-Token` if your client can't send an Authorization header. On cPanel / PHP-FPM hosts, which strip the Authorization header, the installer adds a small rule to `api/.htaccess` to keep it; uninstalling removes it again.

A typical client configuration:

```json
{
  "mcpServers": {
    "joomla-mysite": {
      "type": "http",
      "url": "https://yoursite.com/api/index.php/v1/mcp",
      "headers": { "Authorization": "Bearer YOUR_JOOMLA_API_TOKEN" }
    }
  }
}
```

**Codex** supports the endpoint natively. Keep the token in an environment variable rather than in Codex's configuration:

```sh
export JOOMLA_YOURSITE_COM_MCP_TOKEN='<your-token>'      # Windows: [Environment]::SetEnvironmentVariable('JOOMLA_YOURSITE_COM_MCP_TOKEN', '<your-token>', 'User')
codex mcp add yoursite-com-joomla --url 'https://yoursite.com/api/index.php/v1/mcp' --bearer-token-env-var JOOMLA_YOURSITE_COM_MCP_TOKEN
codex mcp list
```

If the host strips `Authorization`, use `env_http_headers = { "X-Joomla-Token" = "JOOMLA_YOURSITE_COM_MCP_TOKEN" }` in `~/.codex/config.toml` instead. **Claude Desktop** with an API key needs a small `mcp-remote` wrapper (Windows, Node.js); the Setup Guide generates it. With OAuth sign-in on, use Add custom connector instead.

## Permissions

The Joomla user who owns the token (or who signed in) is the actor on every tool call. MCP for J never grants anything that user couldn't do in the Joomla admin.

- **Web Services Login** (`core.login.api`) gates all of Joomla's API, MCP included. Only Super Users have it by default. Grant it to other groups in **System → Global Configuration → Permissions**.
- **Use MCP endpoint** (`csmcpforj.use`) allows read tools; **Write through MCP endpoint** (`csmcpforj.write`) allows tools that change things. Set them in **Components → MCP for J → Options → Permissions**. `core.manage` or `core.admin` on the component also pass, so with Joomla's defaults Super Users and Administrators have both. Managers and other groups need them granted.
- **Six tools are limited to Super Users** whatever the permissions say, because they can change who has power over the site: `install_extension`, `set_component_permission`, `apply_joomla_update`, `set_extension_enabled`, `set_plugin_params`, `set_component_params`.
- Tools that manage other users' API tokens refuse to act on a user whose group is equal to or above the caller's.

**Read-only mode** (Options → MCP server) makes the endpoint announce and accept read-only tools only. A client can also add `?read_only=1` to the endpoint URL to narrow a session further, but can't unlock writes on a site set to read-only. `?categories=articles,users` limits a session to some tool groups.

## Built-in tools (95)

`read` = Use MCP endpoint, `write` = Write through MCP endpoint, `write (Super User)` = also needs a Super User. Every tool publishes its JSON schema and MCP annotations through `tools/list`.

### Articles (6)

| Tool | Access | What it does |
|---|---|---|
| `create_article` | write | Create a new article in com_content. |
| `delete_article` | write | Delete or trash an article (or several at once). |
| `get_article` | read | Fetch a single article by id, returning its core fields, resolved category title, and tags (array of {id, title}). |
| `list_articles` | read | List articles with optional filters: search by title substring, by category, by author, by state, by language. |
| `list_categories` | read | List com_content categories so an article can be assigned to a valid catid. |
| `update_article` | write | Update an existing article. |

### Categories (any extension) (5)

| Tool | Access | What it does |
|---|---|---|
| `create_category` | write | Create a new category. |
| `delete_category` | write | Trash or permanently delete a category. |
| `get_category` | read | Fetch a single category by id, returning its full record (title, alias, extension, parent, level, language, access, description, params)... |
| `list_categories_in` | read | List categories belonging to a specific Joomla extension. |
| `update_category` | write | Update an existing category. |

### Tags (5)

| Tool | Access | What it does |
|---|---|---|
| `create_tag` | write | Create a new tag. |
| `delete_tag` | write | Trash or permanently delete a tag. |
| `get_tag` | read | Fetch a single tag by id. |
| `list_tags` | read | List Joomla tags. |
| `update_tag` | write | Update an existing tag. |

### Menus (7)

| Tool | Access | What it does |
|---|---|---|
| `create_menu_item` | write | Create a menu item. |
| `delete_menu_item` | write | Trash or permanently delete menu item(s). |
| `get_menu_item` | read | Fetch a single menu item by id, including its params/link. |
| `list_menu_item_types` | read | List every menu item type installed on this Joomla site, grouped by component. |
| `list_menu_items` | read | List menu items, optionally filtered by menutype, parent, language, or published state. |
| `list_menus` | read | List menu types (menu containers) — for example "mainmenu", "usermenu". |
| `update_menu_item` | write | Update a menu item. |

### Modules (7)

| Tool | Access | What it does |
|---|---|---|
| `create_module` | write | Create a new module instance. |
| `delete_module` | write | Trash or permanently delete module(s). |
| `get_module` | read | Fetch a single module by id, including content, params, and the menu assignments. |
| `list_module_positions` | read | List module positions currently in use, optionally filtered by client_id (0=site, 1=admin). |
| `list_module_types` | read | List every module type installed on this Joomla site (mod_login, mod_menu, mod_custom, mod_articles_latest, etc.). |
| `list_modules` | read | List published/unpublished modules. |
| `update_module` | write | Update an existing module. |

### Custom fields (11)

| Tool | Access | What it does |
|---|---|---|
| `create_custom_field` | write | Create a custom field. |
| `create_field_group` | write | Create a custom-field group. |
| `delete_custom_field` | write | Delete a custom field. |
| `delete_field_group` | write | Delete a custom-field group. |
| `get_custom_field` | read | Fetch a single custom field by id. |
| `get_field_group` | read | Fetch a single custom-field group by id, including its decoded params blob. |
| `list_custom_fields` | read | List custom fields, optionally filtered by context (e.g. "com_content.article", "com_users.user", "com_contact.contact"). |
| `list_field_groups` | read | List custom-field groups from #__fields_groups, optionally filtered by context (e.g. "com_content.article", "com_users.user", "com_contac... |
| `set_custom_field_value` | write | Set a custom field value on an item (article/user/contact/...). |
| `update_custom_field` | write | Update a custom field. |
| `update_field_group` | write | Update a custom-field group. |

### Users, groups and API tokens (14)

| Tool | Access | What it does |
|---|---|---|
| `create_user` | write | Create a new Joomla user. |
| `create_user_group` | write | Create a new Joomla user group under #__usergroups. |
| `delete_user` | write | Delete one or more users by id. |
| `delete_user_group` | write | Delete a user group. |
| `enable_user_api_token` | write | Toggle a user's API token enabled flag without touching the secret. |
| `get_user` | read | Fetch a single user by id (or by username). |
| `get_user_api_token_status` | read | Report whether a user has an API token configured. |
| `list_access_levels` | read | List viewing access levels (Public, Registered, Special, Super Users, Guest, custom). |
| `list_user_groups` | read | List Joomla user groups (Public, Registered, Author, Editor, Manager, Administrator, Super Users, custom groups). |
| `list_users` | read | List Joomla users. |
| `reset_user_api_token` | write | Generate a fresh API token for a user and return the new display string (format "<algo>:<userid>:<hmac>", base64-encoded). |
| `revoke_user_api_token` | write | Disable a user's API token and zero the stored secret. |
| `update_user` | write | Update an existing user. |
| `update_user_group` | write | Update an existing user group. |

### Permissions (2)

| Tool | Access | What it does |
|---|---|---|
| `list_component_permissions` | read | Return the ACL state for a component or the root asset. |
| `set_component_permission` | write (Super User) | Set one ACL cell on a component or the root asset. |

### Extensions and plugins (8)

| Tool | Access | What it does |
|---|---|---|
| `get_component_params` | read (needs the component's Options right) | Read a component's Options (the settings behind its Options button), with each option's label, default and choices. |
| `get_plugin_params` | read | Read a Joomla plugin's params (its Options screen settings). |
| `install_extension` | write (Super User) | Install (or upgrade) a Joomla extension from a URL or local zip. |
| `list_extensions` | read | List installed extensions of any type (component, plugin, module, template, library, package, language, file). |
| `list_plugins` | read | List installed plugins, optionally filtered by group (system, content, authentication, etc.) and enabled state. |
| `set_component_params` | write (Super User) | Change a component's Options; only the keys you pass change. Permissions, Global Configuration and MCP for J's own options are refused. |
| `set_extension_enabled` | write (Super User) | Enable or disable an installed extension by extension_id. |
| `set_plugin_params` | write (Super User) | Modify a Joomla plugin's params (its Options screen settings). |

### Templates (7)

| Tool | Access | What it does |
|---|---|---|
| `get_template_style` | read | Fetch a single template style by id. |
| `list_template_files` | read | Recursively list every file under a Joomla template's media root (media/templates/<client>/<template>/). |
| `list_template_styles` | read | List template styles. |
| `read_template_file` | read | Return the text contents of a file under a Joomla template's media root (media/templates/<client>/<template>/<path>). |
| `set_default_template_style` | write | Make a template style the default for its client (site or admin). |
| `update_template_style` | write | Update a template style's params (and optionally its title). |
| `write_template_file` | write | Save contents to a file under a Joomla template's media root (media/templates/<client>/<template>/<path>). |

### Languages (2)

| Tool | Access | What it does |
|---|---|---|
| `list_content_languages` | read | List content languages (the records in #__languages used to tag articles, menu items, modules with a language). |
| `list_languages` | read | List installed languages (the language packs in #__extensions). |

### Schema.org / SEO (10)

| Tool | Access | What it does |
|---|---|---|
| `clear_article_schema` | write | Remove the Schema.org row for a content item (sets schemaType=None internally, which Joomla handles by deleting the row). |
| `get_article_schema` | read | Fetch the stored Schema.org data for a content item. |
| `get_schemaorg_site_profile` | read | Read the site-wide Organization/Person profile that plg_system_schemaorg emits on every page. |
| `list_articles_with_schema` | read | List articles with their current Schema.org type assignment. |
| `list_schema_types` | read | List CORE Joomla Schema.org types (Article, BlogPosting, Book, Custom, Event, JobPosting, Organization, Person, Recipe, None) with the ty... |
| `set_article_custom_jsonld` | write | Attach a fully-formed JSON-LD object as Custom schema to a content item. |
| `set_article_custom_jsonld_bulk` | write | Bulk attach Custom JSON-LD to many articles in one call. |
| `set_article_schema` | write | Set/replace the Schema.org data for a content item. |
| `set_schemaorg_site_profile` | write | Set the site-wide Organization/Person profile for plg_system_schemaorg, which renders into every page's JSON-LD <head>. |
| `validate_jsonld` | read | Validate the shape of a JSON-LD object before writing it. |

### System (6)

| Tool | Access | What it does |
|---|---|---|
| `check_for_updates` | read | List extensions with pending updates from the locally cached update sites. |
| `clear_cache` | write | Clear Joomla cache. |
| `fetch_rendered_url` | read | Fetch a rendered page from this same Joomla site (same-origin only — no SSRF). |
| `get_joomla_version` | read | Returns the running Joomla version, codename, release/dev statuses, and the PHP version this Joomla instance is running on. |
| `get_site_info` | read | Returns site name, base URL, locale, timezone, mailer-from settings, debug/SEF flags, default editor, and basic counts (articles, users,... |
| `list_scheduled_tasks` | read | List Joomla scheduled tasks (System → Manage → Scheduled Tasks). |

### Joomla update (3)

| Tool | Access | What it does |
|---|---|---|
| `apply_joomla_update` | write (Super User) | Apply the currently-available Joomla core update: download the package, extract it, and run the upgrade in sequence. |
| `check_joomla_update` | read | Check whether a Joomla core update is available. |
| `joomla_update_healthcheck` | read | Run pre-update health checks before applying a Joomla core update. |

### Admin menu presets (2)

| Tool | Access | What it does |
|---|---|---|
| `get_admin_menu_preset` | read | Read one admin-sidebar preset XML by (component, name). |
| `list_admin_menu_presets` | read | List every admin-sidebar preset XML installed on this site (files under administrator/components/<component>/presets/*.xml). |

## Add-ons

**Browse MCP Add-ons** (Components → MCP for J) lists optional add-ons that give the AI tools for specific third-party extensions: AcyMailing, Akeeba Backup, Akeeba Ticket System, Community Builder, Convert Forms, DPCalendar, J2Commerce, JCE, Maximenu CK, Page Builder CK, VirtueMart and more (31 at the time of writing). Each add-on is a separate plugin; install only the ones you need.

- **Free add-ons** install with one click.
- **Pro add-ons**, for commercial third-party extensions, are sold separately at [cybersalt.com](https://www.cybersalt.com/membership-plans). Link your Cybersalt account once on the Dashboard and they install and update from the same screen.

The add-ons are built independently by Cybersalt; you need the third-party extension itself from its own vendor.

**What contacts cybersalt.com, and when:** opening Browse MCP Add-ons fetches the add-on catalog (cached for 24 hours; a copy is bundled for when it can't be reached). If you link a Cybersalt account, the site registers an installation ID with your domain and email address, and re-checks membership about once a day. Updates come through Joomla's normal update server. The core MCP endpoint itself never calls home.

## Activity log

**Components → MCP for J → Logs** records connections, every tool call (with redacted arguments and timing), refusals and OAuth sign-in steps, with filters and CSV export. Entries are kept 30 days by default (never more than the newest 20,000). Tokens, codes, client secrets and password-like arguments are never written to it.

## Safety features

- The endpoint tells every AI app to treat tool results as untrusted site data and to ask before changing anything.
- Deletes go to the trash by default, and permanently deleting an article or module requires it to be trashed first. Deleting custom fields and applying a Joomla update need an explicit `confirm`.
- Template file tools are confined to `media/templates/<client>/<template>/` and can't write PHP.
- `fetch_rendered_url` only fetches pages from the site itself.
- A tool argument containing the request's own token is refused, a common prompt-injection pattern.
- One broken add-on drops only its own tools; the rest of the endpoint keeps working.

## Extending: custom tools

The system plugin registers the built-in tools by subscribing to the `onCsMcpRegisterTools` event. Any other plugin can subscribe to the same event and register its own `ToolInterface` implementations. See [packages/plg_system_csmcpforj/src/Extension/Csmcpforj.php](packages/plg_system_csmcpforj/src/Extension/Csmcpforj.php) for the pattern.

```php
public function onCsMcpRegisterTools(\Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent $event): void
{
    $event->getRegistry()->register(new MyCustomTool($this->getDatabase()));
}
```

`MyCustomTool` should extend `Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool` and implement the abstract `run()` method.

## Building

```powershell
& .\build.ps1            # test build: pkg_csmcpforj_v{version}_{yyyymmdd}_{hhmm}.zip
& .\build.ps1 -Release   # release build: pkg_csmcpforj_v{version}.zip
```

## Uninstalling

Uninstall the **CS MCP for J** package in System → Manage → Extensions. That removes all four parts, the OAuth and log tables, and the `api/.htaccess` rule. Add-ons are separate extensions and are uninstalled on their own.

## Licensing

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt) and [NOTICE.txt](NOTICE.txt).

This extension is a **clean-room** implementation. It does not derive from, copy, or include any code from Nicholas Dionysopoulos's [MCP4Joomla](https://github.com/nikosdion/joomla-mcp-php) (AGPL-3.0). See `BUILD-NOTES.md` for reference provenance.

## Support

[Cybersalt support form](https://www.cybersalt.com/services/support-request-form), the **Support** screen inside the extension, or [GitHub issues](https://github.com/cybersalt/cs-mcp-for-j/issues).
