<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ConnectionFailureException;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\Exception\PrepareStatementFailureException;
use Throwable;

/**
 * What an AI client is told when a tool call fails with an exception nobody
 * caught on purpose.
 *
 * Up to 2.12.0 the raw getMessage() went back to the client: database errors
 * carried SQL fragments and the real table prefix, PHP TypeErrors carried
 * server paths (Susan's overnight add-on audit, 2026-11-17; seen live as
 * "list_dpcalendar_calendars failed: Illegal mix of collations …"). The
 * caller already holds a valid token, so this was information disclosure,
 * not access, but it ended up in AI transcripts.
 *
 * Rules:
 *   - McpException text is our own wording and passes through unchanged.
 *   - Anything else has server paths replaced with [path] and the table
 *     prefix shown as #__.
 *   - Database errors and PHP internal errors (TypeError, ValueError …) are
 *     shown only to a Super User; everyone else gets a short line pointing
 *     at the MCP for Joomla Logs.
 *   - Whenever the client sees less than the raw message, the full exception
 *     (class, message, location, trace) is written to the Logs first.
 */
final class ErrorText
{
	private const LOGS_HINT = 'The details are in Components → MCP for Joomla → Logs.';

	/**
	 * @param string|null $logEvent Activity-log event for the full exception, or null when
	 *                              the caller has already logged it.
	 */
	public static function forClient(Throwable $e, User $actor, ?string $logEvent, array $logContext = []): string
	{
		if ($e instanceof McpException) {
			return $e->getMessage();
		}

		$raw   = $e->getMessage();
		$clean = self::clean($raw);
		$super = (bool) $actor->authorise('core.admin');

		if (self::isDatabaseError($e)) {
			$text = $super ? 'a database error: ' . $clean : 'a database error. ' . self::LOGS_HINT;
		} elseif ($e instanceof \Error) {
			$text = $super ? 'an internal error (' . self::shortClass($e) . '): ' . $clean : 'an internal error. ' . self::LOGS_HINT;
		} else {
			$text = $clean;
		}

		if ($logEvent !== null && $text !== $raw) {
			ActivityLog::exception('mcp', $logEvent, $e, $logContext + ['user_id' => (int) $actor->id]);
		}

		return $text;
	}

	/** Server paths → [path]; the real table prefix → #__. URLs are left alone. */
	public static function clean(string $message): string
	{
		$message = (string) preg_replace('~(?<![\w:/.])(?:[A-Za-z]:\\\\|/)(?:[\w.\-]+[\\\\/])+[\w.\-]+~', '[path]', $message);

		try {
			$prefix = (string) Factory::getContainer()->get(DatabaseInterface::class)->getPrefix();
		} catch (Throwable $ignored) {
			$prefix = '';
		}

		if (strlen($prefix) >= 2) {
			$message = (string) preg_replace('~(?<![\w#])' . preg_quote($prefix, '~') . '(?=\w)~', '#__', $message);
		}

		return $message;
	}

	private static function isDatabaseError(Throwable $e): bool
	{
		for ($t = $e; $t !== null; $t = $t->getPrevious()) {
			if ($t instanceof ExecutionFailureException
				|| $t instanceof PrepareStatementFailureException
				|| $t instanceof ConnectionFailureException
				|| $t instanceof \mysqli_sql_exception
				|| $t instanceof \PDOException) {
				return true;
			}
		}

		return false;
	}

	private static function shortClass(Throwable $e): string
	{
		$class = get_class($e);
		$pos   = strrpos($class, '\\');

		return $pos === false ? $class : substr($class, $pos + 1);
	}
}
