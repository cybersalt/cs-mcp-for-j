<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjrst\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
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
}
