# `add_rst_ticket_reply` fails in API context — RST reply() chain needs site-app routing

**Status:** Fixed live on virtuemarttemplates.net 2026-05-23. Repo file already updated. Awaiting version bump (v1.7.6) and CHANGELOG entry. **Other RST write tools (Close/Update/Reopen/Notify/Flag/Delete/AddNote) almost certainly have the same problem when they trigger notification emails — see "Forward-looking work" below.**

## TL;DR for Claude-in-VS-Code

I patched **one** file in this repo:

- `packages/plg_system_csmcpforjrst/src/Tools/Tickets/AddTicketReplyTool.php`

The patch:
1. **Bypasses `RsticketsproModelTicket::reply()` entirely** and calls the underlying `RSTicketsProTicketHelper::saveMessage()` directly.
2. **Wraps `saveMessage()` in a `Factory::$application` swap** — temporarily replaces the running `ApiApplication` with a real `SiteApplication` (bootstrapped via `Factory::getContainer()->get(SiteApplication::class)`), then restores the api app in a `finally` block.
3. **Mirrors `reply()`'s consent gate and event fires** (`onBeforeStoreTicketReply` / `onAfterStoreTicketReply`) so any extension hooked into the flow still runs and the behaviour matches the admin reply box.

The patched file has extensive inline comments explaining *why* each piece is there — read those before touching the tool.

Validated end-to-end:
- MCP write path succeeded: ticket 10192 (`TECH-0000000202`) on virtuemarttemplates.net got reply msg id `51128`.
- Customer notification email arrived at `tim@cybersalt.com` at 20:44:02 UTC.
- Tim's reply landed at `support@virtuemarttemplates.net` IMAP as msg UID 784 — confirming the round-trip works.

## The bug

`add_rst_ticket_reply` was failing with one of two errors depending on how far the call got:

1. `Call to a member function getDepartments() on false` — when called via the cs-mcp-for-j API endpoint.
2. `Error loading menu: api` — after the first fix attempt got past the model-load step.

Both are the same underlying problem: **RSTicketsPro 3.x's reply flow assumes it's running inside a SiteApplication**, and the cs-mcp-for-j MCP endpoint runs under an ApiApplication. The api app has no menu, no router, and no front-end MVC model search path.

### Why `reply()` can't run in API context — three failure points

Looking at `administrator/components/com_rsticketspro/models/ticket.php` (RST v3.x), `RsticketsproModelTicket::reply()` does this at line 1248:

```php
public function reply($id, $data, $files)
{
    $model       = $this->getInstance('Submit', 'RsticketsproModel');
    $departments = $model->getDepartments();
    ...
}
```

Three things break in API context:

**(1) `getInstance('Submit', 'RsticketsproModel')` returns false.**
The Submit model lives at `components/com_rsticketspro/models/submit.php` (front-end), and is just a JLoader shim that registers the class to the admin path. Front-end MVC model search paths aren't registered in API/admin context, so `JModelLegacy::getInstance()` returns `false`. The very next line then explodes with "Call to a member function getDepartments() on false."

**(2) Even if you pre-load the Submit class, its constructor needs site menu.**
`RsticketsproModelSubmit` extends `AdminModel`; its constructor reaches into `Factory::getApplication()->getMenu()`. The api app has no menu, so the constructor throws "Error loading menu: api" before any reply work happens. The only thing `reply()` actually *uses* `Submit` for is `$department->upload` (file-upload validation) — we don't support file attachments via MCP anyway, so the whole Submit branch is dead weight in our context.

**(3) Even if you bypass Submit and call `saveMessage()` directly, it still needs site context for email building.**
`RSTicketsProTicketHelper::saveMessage()` builds the customer/staff notification email body using `RSTicketsProHelper::mailRoute()`, which calls `Joomla\CMS\Router\Route::link('site', $url, ...)`. The site router needs the site application's menu loaded. Without it: same "Error loading menu: api" error, this time thrown out of the email-build step at `helpers/ticket.php` line ~972 — but *after* the DB row has already been written at line 802. **This is the source of the orphan-row class of bug if the SiteApplication swap fails — the message row gets inserted, the email send dies, and the `last_reply` / `replies` counters get incremented but no notification goes out.**

## The fix (what the patch actually does)

The full patched code is in `AddTicketReplyTool.php` lines 103–175. Key elements:

### Bypass `reply()`, call the helper directly

```php
require_once $this->rstAdminBase() . '/helpers/ticket.php';

$helper = new \RSTicketsProTicketHelper();
$helper->bind($data);
$saveOk = $helper->saveMessage();
```

`saveMessage()` is what `reply()` ultimately delegates to anyway — it does the DB write, fires the `onAfterStoreTicketReply` event (which triggers the RST notification-email listener plugins), and updates `last_reply` / `replies` / `status_id`. By calling it directly we skip the whole broken Submit-model prelude.

### Mirror the consent gate + the outer event triggers reply() fires

```php
if (\RSTicketsProHelper::getConfig('forms_consent')) {
    if (\RSTicketsProHelper::isStaff($data['user_id'])
        && \RSTicketsProHelper::getConfig('forms_consent_staff_skip')) {
        $data['consent'] = 1;
    }
}

\RSTicketsProHelper::trigger('onBeforeStoreTicketReply', [$data]);
// ... saveMessage() runs here (and fires the same events internally) ...
\RSTicketsProHelper::trigger('onAfterStoreTicketReply', [$data]);
```

**Note the double-event-fire.** `saveMessage()` fires `onBeforeStoreTicketReply` / `onAfterStoreTicketReply` internally; `reply()` also fires them around the `saveMessage()` call. We preserve that — any extension listening to these events expects the double-fire because that's what the official reply() method does. Don't "fix" it by removing one set.

### The SiteApplication swap (the critical part)

```php
$originalApp = Factory::$application;
try {
    $container = Factory::getContainer();
    $siteApp = $container->get(SiteApplication::class);
    $siteApp->loadLanguage();
    Factory::$application = $siteApp;

    $helper = new \RSTicketsProTicketHelper();
    $helper->bind($data);
    $saveOk = $helper->saveMessage();
    $saveErr = $helper->getError();
} finally {
    Factory::$application = $originalApp;
}
```

This is the same trick Joomla's own CLI tasks use when they need to invoke front-end code that builds SEF URLs. Bootstrapping a SiteApplication via the DI container gets us a fully wired-up site app (with a menu, a router, language strings) without leaving the api request. The `try/finally` guarantees we restore the api app even if `saveMessage()` throws — crucial because the surrounding MCP envelope still needs the api app to serialize its response.

### Extra import required

```php
use Joomla\CMS\Application\SiteApplication;
```

(Added near the top of the file alongside the other Joomla imports.)

### Data array additions

Two fields needed beyond what reply() builds (because we're calling saveMessage() directly, not the wrapper that builds them):

- `'customer_id' => (int) ($ticket->customer_id ?? 0)` — pulled from the fetched ticket row.
- `'files' => []` — the file-upload array reply() would normally build via the Submit model. Empty because we don't ship attachments via MCP.

## Forward-looking work (recommended)

### 1. Extract the SiteApplication-swap into a trait helper

Right now the swap pattern is inline in `AddTicketReplyTool.php`. The same pattern will be needed by every other RST write tool that fires emails. Recommended: add a method to `RSTicketsProBootTrait`:

```php
/**
 * Run $fn with Factory::$application temporarily replaced by a bootstrapped
 * SiteApplication. Restores the original (api) app in finally. Use this
 * whenever calling RST code that touches Route::link('site', ...) or
 * otherwise needs site menu/router — saveMessage(), sendNotificationEmail(),
 * any email-building helper.
 */
protected function withSiteAppContext(callable $fn)
{
    $originalApp = \Joomla\CMS\Factory::$application;
    try {
        $container = \Joomla\CMS\Factory::getContainer();
        $siteApp = $container->get(\Joomla\CMS\Application\SiteApplication::class);
        $siteApp->loadLanguage();
        \Joomla\CMS\Factory::$application = $siteApp;
        return $fn();
    } finally {
        \Joomla\CMS\Factory::$application = $originalApp;
    }
}
```

Then `AddTicketReplyTool::run()` becomes:

```php
[$saveOk, $saveErr] = $this->withSiteAppContext(function () use ($data) {
    $helper = new \RSTicketsProTicketHelper();
    $helper->bind($data);
    return [$helper->saveMessage(), $helper->getError()];
});
```

Cleaner, and every future write tool just wraps its email-firing block in `withSiteAppContext(...)`.

### 2. Audit the other RST write tools

Files to check (all in `packages/plg_system_csmcpforjrst/src/Tools/Tickets/`):

| Tool | Fires email? (likely) | Needs SiteApp swap? |
|------|---|---|
| `CloseTicketTool.php` | Yes — RST sends `close_ticket` notification | **Probably** |
| `ReopenTicketTool.php` | Yes — RST sends `reopen_ticket` notification | **Probably** |
| `UpdateTicketTool.php` | Depends on what fields it updates — status/priority/assignment changes trigger emails | **Probably** |
| `NotifyTicketTool.php` | Yes by definition — it exists to send notifications | **Almost certainly** |
| `FlagTicketTool.php` | Maybe — flagging may notify staff | Possibly |
| `DeleteTicketTool.php` | Probably not (no recipient by definition) | Probably not |
| `AddTicketNoteTool.php` | Maybe — internal notes can notify staff in some RST configs | Possibly |

Verification approach for each: trace from the tool's call into the relevant `RsticketsproModelTicket::*` method, follow it down to whatever helper does the work, and grep for `Route::link('site',` or `mailRoute(` or `sendMail(` in the chain. If found → needs the swap.

The cheapest way to know for sure is to actually test each tool against a live RST install (preferably a test ticket on virtuemarttemplates.net since the MCP is already configured there).

### 3. Front-end Submit-model load path

If a future tool genuinely *does* need RST's front-end Submit model loaded (e.g. for file attachment validation we don't ship today), the JLoader-shim approach won't work in admin/api context — the Submit model's constructor needs site menu the same way. The right pattern is the same as above: do the model instantiation inside a `withSiteAppContext(...)` callback so the constructor sees a real site app.

## Edge case to be aware of: orphan rows from failed sends

If `saveMessage()` *partially* succeeds — DB row written but email build dies — you get a "phantom reply": the message exists in `#__rsticketspro_ticket_messages`, the ticket's `replies` counter is incremented, but no notification went out. We hit this twice during debugging on ticket 10192 (rows 51126, 51127) before the SiteApplication swap landed. Cleanup is direct SQL:

```sql
DELETE FROM <prefix>_rsticketspro_ticket_messages WHERE id IN (...);
UPDATE <prefix>_rsticketspro_tickets SET replies = replies - N WHERE id = <ticket_id>;
```

With the SiteApplication swap in place this shouldn't happen anymore — but if a future RST upgrade changes the saveMessage() flow and re-introduces the failure path, that's the cleanup pattern. Worth wrapping `saveMessage()` in a transaction once we have a chance, so a failed email build rolls back the DB write. Not done in v1.7.6 because the failure mode is gone in practice.

## Validation evidence

- **MCP call succeeded** — `add_rst_ticket_reply(ticket_id=10192, message="...")` returned `{"ok": true, "replies": 2, ...}`.
- **Outbound notification** — Gmail message `19e5694a6268113d` delivered to `tim@cybersalt.com` at 20:44:02 UTC 2026-05-23, subject matching the standard `add_ticket_reply_customer` template.
- **Inbound round-trip** — Tim replied from `tim@cybersalt.com`; IMAP scan of `support@virtuemarttemplates.net` (`mail.virtuemarttemplates.net:993`) confirmed UID 784 landed at 13:55:04 PDT, `From: Tim Davis <tim@cybersalt.com>`, `Subject: Re: [TECH-0000000202] this is a test`.
- **DB state on virtuemarttemplates.net** post-cleanup: ticket 10192 shows `replies=2`, `last_reply=2026-05-23 20:44:02`, two real message rows (51125 customer test message, 51128 the successful staff reply).

## File changed in this commit

- `packages/plg_system_csmcpforjrst/src/Tools/Tickets/AddTicketReplyTool.php` — bypass reply(), direct helper call wrapped in SiteApplication swap, mirrored consent gate + event fires, added `customer_id` + `files` to data array.

## Files to consider for the v1.7.6 release

- `CHANGELOG.md` — add v1.7.6 entry (entry text below).
- `BUILD-NOTES.md` (if present) — note the SiteApplication-swap pattern as a building block for other RST write tools.
- `packages/plg_system_csmcpforjrst/src/Tools/RSTicketsProBootTrait.php` — if doing the trait extraction now, add `withSiteAppContext()` method.
- Version constants in any manifest/XML files — bump 1.7.5 → 1.7.6.

## Suggested CHANGELOG entry

```
## 🚀 Version 1.7.6 (May 23, 2026)

### 🐛 Bug Fixes

- **`add_rst_ticket_reply` failed end-to-end in API context with "Error loading menu: api"** (or, on earlier code paths, "Call to a member function getDepartments() on false"). RSTicketsPro 3.x's reply flow assumes it's running inside a SiteApplication — the api app the MCP endpoint runs under has no menu, no router, and no front-end MVC model search path. Three failure layers: (1) `JModelLegacy::getInstance('Submit', 'RsticketsproModel')` returns false because front-end model search paths aren't registered in api context, (2) even pre-loaded the Submit model constructor needs site menu, (3) `saveMessage()` calls `Route::link('site', ...)` when building the customer-notification email body which also needs site menu. Fix: bypass `RsticketsproModelTicket::reply()` and call `RSTicketsProTicketHelper::saveMessage()` directly, wrapped in a `Factory::$application` swap that temporarily replaces the api app with a real SiteApplication bootstrapped via the DI container, restored in `finally`. Consent gate + `onBeforeStoreTicketReply` / `onAfterStoreTicketReply` event triggers preserved from the original `reply()` flow. Validated round-trip on virtuemarttemplates.net ticket TECH-0000000202: MCP reply posted → customer notification email delivered → inbound reply confirmed at support@vmt IMAP. **Heads-up: other RST write tools that fire notification emails (CloseTicketTool, ReopenTicketTool, NotifyTicketTool, UpdateTicketTool, etc.) almost certainly have the same problem — they should be audited and likely patched with the same pattern. See `resolved-issues/ISSUE-5-add_rst_ticket_reply-site-app-context.md` for the full investigation and the proposed `withSiteAppContext()` trait helper.**

### ⚠️ Known limitations carried forward

- File attachments still not supported via `add_rst_ticket_reply` — the front-end Submit-model upload path needs the same SiteApplication-swap treatment to work in API context, and we don't have a multipart upload path through the MCP layer yet anyway. Use the admin reply box for replies that need attachments.
```

## Discovered on

virtuemarttemplates.net (RSTicketsPro 3.x, Joomla 5/6) during cs-mcp-for-j v1.7.5 live integration validation, 2026-05-23. Ticket TECH-0000000202 (id 10192) was the test vehicle.
