<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;

/**
 * Bootstrap helpers for talking to RSTicketsPro from inside an MCP tool.
 *
 * RSTicketsPro 3.x is a legacy non-namespaced Joomla MVC component
 * (classes like `RsticketsproModelTicket`, `RsticketsproTableTickets`),
 * so we can't rely on PSR-4 autoloading the way the core-Joomla tools
 * do for com_content / com_menus / etc. This trait does the manual
 * require_once dance and exposes typed accessors for models + tables.
 *
 * Side benefit: by always going through these accessors, tools stay
 * uniform — if RSTicketsPro ever modernises and gets a service provider
 * + MVCFactory, only this trait changes.
 */
trait RSTicketsProBootTrait
{
	/** Loaded class names → file paths (per-request memoisation). */
	private static array $rstLoaded = [];

	/**
	 * Returns the absolute admin component path, or null if RSTicketsPro
	 * isn't installed.
	 */
	protected function rstAdminBase(): ?string
	{
		$path = JPATH_ADMINISTRATOR . '/components/com_rsticketspro';
		return is_dir($path) ? $path : null;
	}

	/**
	 * Ensure the static helper class + constants are loaded. RSTicketsPro's
	 * models call into `RSTicketsProHelper::getConfig(...)` and the
	 * `RST_STATUS_*` / `RST_PRIORITY_*` constants from helpers/rsticketspro.php.
	 *
	 * Returns false (and the caller should return notInstalledError()) when
	 * the component isn't on disk.
	 */
	protected function ensureRstLoaded(): bool
	{
		$base = $this->rstAdminBase();
		if ($base === null) {
			return false;
		}

		foreach ([
			'/helpers/rsticketspro.php',
			'/helpers/adapter.php',
		] as $rel) {
			$file = $base . $rel;
			if (isset(self::$rstLoaded[$file])) {
				continue;
			}
			if (is_file($file)) {
				require_once $file;
			}
			self::$rstLoaded[$file] = true;
		}

		return true;
	}

	/**
	 * Load an RSTicketsPro admin model class and return an instance.
	 *
	 * @param string $name e.g. 'Ticket' / 'Tickets' / 'Note' / 'Notes' / 'Department'
	 */
	protected function rstModel(string $name): ?object
	{
		if (!$this->ensureRstLoaded()) {
			return null;
		}
		$base = $this->rstAdminBase();
		$file = $base . '/models/' . strtolower($name) . '.php';
		if (!is_file($file)) {
			return null;
		}
		if (!isset(self::$rstLoaded[$file])) {
			require_once $file;
			self::$rstLoaded[$file] = true;
		}
		$class = 'RsticketsproModel' . ucfirst(strtolower($name));
		return class_exists($class) ? new $class() : null;
	}

	/**
	 * Load an RSTicketsPro JTable class and return an instance bound to
	 * the MCP component's database.
	 */
	protected function rstTable(string $name): ?Table
	{
		if (!$this->ensureRstLoaded()) {
			return null;
		}
		$base = $this->rstAdminBase();
		$file = $base . '/tables/' . strtolower($name) . '.php';
		if (!is_file($file)) {
			return null;
		}
		if (!isset(self::$rstLoaded[$file])) {
			require_once $file;
			self::$rstLoaded[$file] = true;
		}
		$class = 'RsticketsproTable' . ucfirst(strtolower($name));
		return class_exists($class) ? new $class($this->db) : null;
	}

	/** Standard error response for "RSTicketsPro isn't on this site." */
	protected function notInstalledError(): ToolResult
	{
		return ToolResult::error(
			'RSTicketsPro (com_rsticketspro) is not installed on this site, or the install is incomplete.'
		);
	}

	/**
	 * Verify the calling actor is recognised as a RSTicketsPro staff member
	 * (most write operations require this — the underlying model does its
	 * own check via $model->isStaff() and will reject writes otherwise).
	 *
	 * Note: this is purely informational/preflight; the model is the
	 * authoritative gate. We surface a clearer error than RSTicketsPro's
	 * generic "permission denied" when the issue is "you're not staff."
	 */
	protected function actorIsRstStaff(): bool
	{
		if (!$this->ensureRstLoaded()) {
			return false;
		}
		// RSTicketsProHelper::isStaff() reads Factory::getUser() if no arg.
		return class_exists('RSTicketsProHelper')
			&& \RSTicketsProHelper::isStaff(Factory::getUser()->id);
	}

	/**
	 * Read a fresh ticket row by id, bypassing RsticketsproModelTicket::getTicket()'s
	 * static cache. Use this after any write that goes through the model — the
	 * cache is keyed by id and never invalidated by updateInfo() / toggleTime() /
	 * setFlag() / etc., so the model's own getTicket() will keep returning the
	 * pre-write state for the rest of the request.
	 *
	 * Returns a flat assoc array with all ticket columns + JOIN'd labels for
	 * department, status, priority, staff (user_name + user_email), customer
	 * (user_name + user_email). Matches what list_rst_tickets returns per row.
	 *
	 * Returns null when the ticket doesn't exist.
	 *
	 * IMPORTANT — tickets.staff_id is a Joomla user_id, not a _rsticketspro_staff
	 * PK. Confirmed by inspecting models/fields/staff.php which emits $user->id as
	 * the option value, and by models/ticket.php::staffHasAccessToDepartment($user_id, ...)
	 * which accepts the column value as a user_id. JOINs reflect that.
	 */
	protected function fetchTicketRow(int $id): ?array
	{
		$prefix = $this->db->getPrefix();

		$query = $this->db->getQuery(true)
			->select('t.*')
			->select($this->db->quoteName('s.name', 'status'))
			->select($this->db->quoteName('d.name', 'department'))
			->select($this->db->quoteName('p.name', 'priority'))
			->select($this->db->quoteName('su.name', 'staff_name'))
			->select($this->db->quoteName('su.email', 'staff_email'))
			->select($this->db->quoteName('cu.name', 'customer_name'))
			->select($this->db->quoteName('cu.email', 'customer_email'))
			->from($this->db->quoteName($prefix . 'rsticketspro_tickets', 't'))
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_departments', 'd') . ' ON d.id = t.department_id')
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_statuses', 's') . ' ON s.id = t.status_id')
			->join('LEFT', $this->db->quoteName($prefix . 'rsticketspro_priorities', 'p') . ' ON p.id = t.priority_id')
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'cu') . ' ON cu.id = t.customer_id')
			->join('LEFT', $this->db->quoteName($prefix . 'users', 'su') . ' ON su.id = t.staff_id')
			->where($this->db->quoteName('t.id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return null;
		}

		// Coerce integer columns for tighter agent output.
		foreach (['id', 'status_id', 'department_id', 'priority_id', 'staff_id', 'customer_id', 'last_reply_customer', 'replies', 'flagged', 'has_files', 'autoclose_sent', 'logged', 'feedback', 'followup_sent'] as $k) {
			if (array_key_exists($k, $row)) {
				$row[$k] = (int) $row[$k];
			}
		}
		return $row;
	}

	/**
	 * Read a single value from #__rsticketspro_configuration. The table is
	 * a flat key/value store (rows have `name` + `value` columns). Returns
	 * null when the key doesn't exist.
	 *
	 * Cheap direct-SQL path — avoids booting RSTicketsProHelper just to read
	 * a single config row, and works correctly in API context (RSTicketsProHelper::
	 * getConfig() has a static cache + assumes site app context for some calls).
	 */
	protected function getRstConfig(string $key): ?string
	{
		$prefix = $this->db->getPrefix();
		$q = $this->db->getQuery(true)
			->select($this->db->quoteName('value'))
			->from($this->db->quoteName($prefix . 'rsticketspro_configuration'))
			->where($this->db->quoteName('name') . ' = ' . $this->db->quote($key));
		$v = $this->db->setQuery($q)->loadResult();
		return $v === null ? null : (string) $v;
	}

	/**
	 * Run $fn with Factory::$application temporarily replaced by a real
	 * bootstrapped SiteApplication, then restore the original (api) app in
	 * a finally block. Returns whatever $fn returns.
	 *
	 * WHY THIS EXISTS — extracted from ISSUE-5
	 * ---------------------------------------
	 * RSTicketsPro 3.x's write paths assume they're running inside a
	 * SiteApplication. The cs-mcp-for-j MCP endpoint runs under an
	 * ApiApplication which has no menu, no router, and no front-end MVC
	 * model search path. Several RST code paths blow up in api context:
	 *
	 *   - RSTicketsProTicketHelper::saveMessage()  → calls Route::link('site', ...)
	 *     when building the customer-notification email body. Site router needs
	 *     site menu. Without it: "Error loading menu: api".
	 *
	 *   - RSTicketsProModelTicket::updateInfo()    → on a department change or
	 *     staff-assignment change, fires RSTicketsProEmailsHelper::sendEmail()
	 *     which builds emails the same way — same Route::link('site', ...) trap.
	 *
	 *   - RSTicketsProModelTicket::notify()        → autoclose-warning email,
	 *     same trap.
	 *
	 *   - RSTicketsProModelTicket::reply()         → tries to load the front-end
	 *     Submit model which fails outright in api context (the bug AddTicketReply
	 *     hits by name — we route around it entirely by calling saveMessage()).
	 *
	 * The fix everywhere is the same: temporarily install a SiteApplication via
	 * `Factory::$application = ...`, do the RST call, restore the api app. This
	 * is the same trick Joomla's own CLI tasks use when they invoke front-end
	 * code that builds SEF URLs.
	 *
	 * WHEN TO USE
	 * -----------
	 * Wrap any call to RST model methods that may fire an email or build a
	 * site URL. In practice: updateInfo(), reply()/saveMessage(), notify(),
	 * and anything else that descends into RSTicketsProHelper::mailRoute()
	 * or RSTicketsProEmailsHelper::sendEmail(). Safe to wrap unnecessarily —
	 * the cost of the swap when the inner code happens not to need it is
	 * one container lookup plus restoring a property.
	 *
	 * Tools that DON'T need it (per ISSUE-5 audit + source check):
	 *   - setFlag()  — plain UPDATE statement, no Route::link
	 *   - delete()   — JTable delete cascade, no Route::link
	 *   - Ticketnotes JTable save (AddTicketNote) — plain INSERT
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	protected function withSiteAppContext(callable $fn)
	{
		$originalApp = Factory::$application;
		try {
			$container = Factory::getContainer();
			$siteApp   = $container->get(SiteApplication::class);
			$siteApp->loadLanguage();
			Factory::$application = $siteApp;
			return $fn();
		} finally {
			Factory::$application = $originalApp;
		}
	}
}
