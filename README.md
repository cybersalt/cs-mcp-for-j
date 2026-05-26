# Cybersalt MCP for Joomla (`cs-mcp-for-j`)

Turns a Joomla 5/6 site into its own MCP server. Connect Claude (Desktop, Code, claude.ai) directly to your site using a Joomla API token — no local Node/Python/WSL install, no MCP server process to babysit.

> **Status:** v1.8.1 — 110 built-in tools across 14 domains. Self-installing copy-paste prompt with token-substitute UI + manual MCP connector setup. Includes a **4SEO add-on (19 tools)** for sites running the Weeblr 4SEO extension — typed wrappers for per-page meta overrides, site-wide LocalBusiness profile, and config, plus generic CRUD escape hatches. v1.8.0 added a **RSTicketsPro add-on (20 tools)** for sites running RSJoomla!'s helpdesk extension — full ticket workflow (list / get / reply / note / update / close / reopen / flag / notify / delete) calling into RST's own AdminModel so every email notification, ticket_history audit entry, dept-change code regeneration, and staff-access validation happens automatically. v1.8.1 fills out the **Custom Fields domain** with full CRUD over both fields (incl. `update_custom_field` / `delete_custom_field`) and a new field-groups sub-domain (5 tools) so programmatic setup of a clean field group on an article context is one call rather than 6+ admin clicks.

## What it ships

| Element | Type | Job |
|---|---|---|
| `com_csmcpforj` | Component | Owns the `/api/index.php/v1/mcp` route, the JSON-RPC server, the tool registry, and ACL gating. |
| `plg_webservices_csmcpforj` | Web Services plugin | Registers the route. **Required** — without it the route 404s. |
| `plg_system_csmcpforj` | System plugin | Registers the built-in tools and translates `Authorization: Bearer` headers into `X-Joomla-Token`. |

All three are bundled in `pkg_csmcpforj` and enabled automatically on install.

## Connecting a client

1. **Generate a Joomla API token** for the user account that should perform the actions. (Joomla admin → System → Users → My Profile → Joomla API Token, click the eye icon.)
2. **Permissions** — Super Users, Administrators, and Managers all work out of the box. For any other user group, grant `Use MCP endpoint` and/or `Write through MCP endpoint` in System → Permissions on the component.
3. **Configure your MCP client.** Either header works:

   **Claude Desktop / `claude_desktop_config.json`:**
   ```json
   {
     "mcpServers": {
       "joomla-mysite": {
         "type": "http",
         "url": "https://yoursite.com/api/index.php/v1/mcp",
         "headers": {
           "Authorization": "Bearer YOUR_JOOMLA_API_TOKEN"
         }
       }
     }
   }
   ```

   **Or, if your client only allows custom headers:**
   ```json
   "headers": { "X-Joomla-Token": "YOUR_JOOMLA_API_TOKEN" }
   ```

## Built-in tools

`read` tools require ACL `csmcpforj.use`; `write` tools require `csmcpforj.write`. Super Users / Administrators / Managers pass both automatically.

### Articles
| Tool | Access |
|---|---|
| `list_articles` | read |
| `get_article` | read |
| `list_categories` | read |
| `create_article` | write |
| `update_article` | write |
| `delete_article` | write |

### Categories (any extension)
| Tool | Access |
|---|---|
| `list_categories_in` | read |
| `get_category` | read |
| `create_category` | write |
| `update_category` | write |
| `delete_category` | write |

### Tags
| Tool | Access |
|---|---|
| `list_tags` | read |
| `get_tag` | read |
| `create_tag` | write |
| `update_tag` | write |
| `delete_tag` | write |

### Menus
| Tool | Access |
|---|---|
| `list_menus` | read |
| `list_menu_items` | read |
| `get_menu_item` | read |
| `create_menu_item` | write |
| `update_menu_item` | write |
| `delete_menu_item` | write |

### Users & Access
| Tool | Access |
|---|---|
| `list_users` | read |
| `get_user` | read |
| `list_user_groups` | read |
| `list_access_levels` | read |
| `create_user` | write |
| `update_user` | write |
| `delete_user` | write |

### Modules
| Tool | Access |
|---|---|
| `list_modules` | read |
| `list_module_positions` | read |
| `get_module` | read |
| `create_module` | write |
| `update_module` | write |
| `delete_module` | write |

### Extensions / Plugins
| Tool | Access |
|---|---|
| `list_extensions` | read |
| `list_plugins` | read |
| `set_extension_enabled` | write |

### Templates
| Tool | Access |
|---|---|
| `list_template_styles` | read |
| `set_default_template_style` | write |

### Languages
| Tool | Access |
|---|---|
| `list_languages` | read |
| `list_content_languages` | read |

### Custom Fields
| Tool | Access |
|---|---|
| `list_custom_fields` | read |
| `get_custom_field` | read |
| `create_custom_field` | write |
| `update_custom_field` | write |
| `delete_custom_field` | write |
| `set_custom_field_value` | write |
| `list_field_groups` | read |
| `get_field_group` | read |
| `create_field_group` | write |
| `update_field_group` | write |
| `delete_field_group` | write |

### System
| Tool | Access |
|---|---|
| `get_joomla_version` | read |
| `get_site_info` | read |
| `list_scheduled_tasks` | read |
| `check_for_updates` | read |
| `clear_cache` | write |

### Schema.org / SEO

Wraps Joomla's CORE Schema.org system (the `plg_system_schemaorg` plugin family — Article, BlogPosting, Book, Custom, Event, JobPosting, Organization, Person, Recipe). Writes go directly to `#__schemaorg` in the same shape Joomla's `onContentAfterSave` hook produces, so the rendered `<script type="application/ld+json">` blocks pick them up on the next page load. For schema types Joomla does not ship a form for (FAQPage, Service, LocalBusiness, Product, Review, BreadcrumbList, etc.), use `set_article_custom_jsonld` to attach arbitrary JSON-LD as the `Custom` type.

| Tool | Access |
|---|---|
| `list_schema_types` | read |
| `list_articles_with_schema` | read |
| `get_article_schema` | read |
| `set_article_schema` | write |
| `set_article_custom_jsonld` | write |
| `clear_article_schema` | write |
| `get_schemaorg_site_profile` | read |
| `set_schemaorg_site_profile` | write |

### 4SEO (`plg_system_csmcpforj4seo` add-on)

Tools for sites running the Weeblr **4SEO** commercial extension (`com_forseo`). 4SEO ships no public Web Services API, so these tools work directly against `#__forseo_*` tables. Every write tool refuses any table not starting with `forseo_`. UPDATE / DELETE refuse if the WHERE clause would touch more than one row. Run `list_4seo_tables` and `describe_4seo_table` first so the agent learns the schema before constructing reads/writes.

| Tool | Access |
|---|---|
| `list_4seo_tables` | read |
| `describe_4seo_table` | read |
| `count_4seo_rows` | read |
| `get_4seo_component_info` | read |
| `get_4seo_component_params` | read |
| `set_4seo_component_params` | write |
| `query_4seo_table` | read |
| `insert_4seo_row` | write |
| `update_4seo_row` | write |
| `delete_4seo_row` | write |
| `get_4seo_business_profile` | read |
| `set_4seo_business_profile` | write |
| `clear_4seo_business_profile` | write |

This add-on is **bundled with the package for testing right now** but is structured as a separate plugin (`plg_system_csmcpforj4seo`) so it can split out into its own paid SKU later. The free core (`com_csmcpforj` + `plg_system_csmcpforj` + `plg_webservices_csmcpforj`) doesn't depend on it — uninstall the 4SEO add-on plugin and the rest still works.

### RSTicketsPro (`plg_system_csmcpforjrst` add-on)

Tools for sites running the RSJoomla! **RSTicketsPro** commercial helpdesk extension (`com_rsticketspro`). Calls into RSTicketsPro's own `AdminModel` write methods (`$model->reply()`, `$model->updateInfo()`, `$model->notify()`, etc.) so every email notification, ticket_history audit entry, dept-change ticket-code regeneration, and staff-access validation fires the same way it would when a human clicks through the admin UI. Includes a shared `withSiteAppContext()` trait helper that bootstraps a SiteApplication for RST calls that need site routing (`Route::link('site', ...)` in notification email bodies) — without this, the api app the MCP endpoint runs under hits "Error loading menu: api" on every email-firing write.

| Tool | Access |
|---|---|
| `list_rst_tickets` | read |
| `get_rst_ticket` | read |
| `get_rst_ticket_messages` | read |
| `get_rst_ticket_history` | read |
| `get_rst_ticket_notes` | read |
| `get_rst_ticket_files` | read |
| `list_rst_departments` | read |
| `list_rst_statuses` | read |
| `list_rst_priorities` | read |
| `list_rst_staff` | read |
| `list_rst_groups` | read |
| `list_rst_custom_fields` | read |
| `add_rst_ticket_reply` | write |
| `add_rst_ticket_note` | write |
| `update_rst_ticket` | write |
| `close_rst_ticket` | write |
| `reopen_rst_ticket` | write |
| `flag_rst_ticket` | write |
| `notify_rst_ticket` | write |
| `delete_rst_ticket` | write |

Same separable-bundled-plugin pattern as the 4SEO add-on — uninstall `plg_system_csmcpforjrst` and the rest of cs-mcp-for-j keeps working. **NOTE:** the API token's Joomla user must be an RSTicketsPro staff member (with permissions matching the operation) for write tools to succeed; RST's own permission gates run inside the model and reject calls from non-staff users with a "permission denied" error.

## Extending — custom tools

The system plugin registers built-in tools by subscribing to the `onCsMcpRegisterTools` event. Any other plugin can subscribe to the same event and register its own `ToolInterface` implementations. See [packages/plg_system_csmcpforj/src/Extension/Csmcpforj.php](packages/plg_system_csmcpforj/src/Extension/Csmcpforj.php) for the pattern.

```php
public function onCsMcpRegisterTools(\Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent $event): void
{
    $event->getRegistry()->register(new MyCustomTool($this->getDatabase()));
}
```

`MyCustomTool` should extend `Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool` and implement the abstract `run()` method.

## Building

```powershell
& .\build.ps1
```

Produces `pkg_csmcpforj_v{version}_{yyyymmdd}_{hhmm}.zip` in the project root.

## Licensing

GPL-2.0-or-later. See `LICENSE.txt`.

This extension is a **clean-room** implementation. It does not derive from, copy, or include any code from Nicholas Dionysopoulos's [MCP4Joomla](https://github.com/nikosdion/joomla-mcp-php) (AGPL-3.0). See `BUILD-NOTES.md` for reference provenance.

## Reporting issues

[support@cybersalt.com](mailto:support@cybersalt.com)
