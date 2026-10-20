<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Filter\InputFilter;
use Joomla\Database\ParameterType;

/**
 * The article author arguments (`created_by`, `created_by_alias`) shared by
 * create_article and update_article. Added in 2.9.2 at Susan's request: she
 * merges duplicate articles on cybersalt.org and needs the surviving copy to
 * keep the original author.
 *
 * A trait in the core plugin rather than helpers on AbstractTool, because
 * add-ons extend AbstractTool too: a new protected method there would fatal
 * any add-on that already declares a private method with the same name.
 */
trait ArticleAuthorTrait
{
	/**
	 * Resolve `created_by` to an existing user. Accepts a user id (an integer,
	 * or a string of digits), a username, or an email address. Refuses a value
	 * that matches no user, or more than one (for example a digits-only
	 * username that is also another user's id).
	 *
	 * @return array{id: int, name: string, username: string}
	 */
	private function resolveArticleAuthor(mixed $value): array
	{
		if (is_float($value) && floor($value) === $value) {
			$value = (int) $value;
		}

		$db         = $this->db;
		$candidates = [];

		$byId = function (int $id) use ($db): ?array {
			$row = $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName(['id', 'name', 'username']))
					->from($db->quoteName('#__users'))
					->where($db->quoteName('id') . ' = :id')
					->bind(':id', $id, ParameterType::INTEGER)
			)->loadAssoc();

			return $row ?: null;
		};

		if (is_int($value)) {
			if ($value <= 0) {
				throw new McpException(-32602, 'created_by must be a positive user id, a username or an email address; got ' . $value . '.');
			}

			$row = $byId($value);
			if ($row === null) {
				throw new McpException(-32602, 'created_by: no user with id ' . $value . ' exists on this site. Use list_users to find the author.');
			}

			return $this->authorShape($row);
		}

		if (!is_string($value) || trim($value) === '') {
			throw new McpException(-32602, 'created_by must be a user id, a username or an email address; got ' . json_encode($value) . '.');
		}

		$needle = trim($value);

		if (ctype_digit($needle) && ($row = $byId((int) $needle)) !== null) {
			$candidates[(int) $row['id']] = $row;
		}

		$columns = ['username'];
		if (str_contains($needle, '@')) {
			$columns[] = 'email';
		}

		foreach ($columns as $column) {
			$rows = (array) $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName(['id', 'name', 'username']))
					->from($db->quoteName('#__users'))
					->where('LOWER(' . $db->quoteName($column) . ') = LOWER(:needle)')
					->bind(':needle', $needle)
			)->loadAssocList();

			foreach ($rows as $row) {
				$candidates[(int) $row['id']] = $row;
			}
		}

		if ($candidates === []) {
			throw new McpException(-32602, 'created_by: no user matches ' . json_encode($needle)
				. ' by id, username or email. Use list_users to find the author.');
		}

		if (count($candidates) > 1) {
			$names = array_map(
				static fn (array $r): string => '#' . $r['id'] . ' ' . $r['username'],
				array_values($candidates)
			);

			throw new McpException(-32602, 'created_by: ' . json_encode($needle) . ' matches more than one user ('
				. implode(', ', $names) . '). Pass the user id as an integer.');
		}

		return $this->authorShape(reset($candidates));
	}

	/** `created_by_alias` must be a string; an empty string clears it. */
	private function articleAuthorAlias(mixed $value): string
	{
		if (!is_string($value)) {
			throw new McpException(-32602, 'created_by_alias must be a string (an empty string clears it); got ' . json_encode($value) . '.');
		}

		// The same TRIM filter ArticleModel::save() applies (it also strips
		// non-breaking and ideographic spaces), so the read-back check compares
		// like with like.
		$value = (string) InputFilter::getInstance()->clean($value, 'TRIM');

		if (mb_strlen($value) > 255) {
			throw new McpException(-32602, 'created_by_alias is limited to 255 characters; got ' . mb_strlen($value) . '.');
		}

		return $value;
	}

	/**
	 * Read back what is actually stored, so a save that reported success but
	 * left the author unchanged (a plugin rewriting it, say) is caught.
	 *
	 * @return array{created_by: int, created_by_alias: string}|null
	 */
	private function storedArticleAuthor(int $articleId): ?array
	{
		$db  = $this->db;
		$row = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['created_by', 'created_by_alias']))
				->from($db->quoteName('#__content'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $articleId, ParameterType::INTEGER)
		)->loadAssoc();

		return $row ? ['created_by' => (int) $row['created_by'], 'created_by_alias' => (string) $row['created_by_alias']] : null;
	}

	/** @return array{id: int, name: string, username: string} */
	private function authorShape(array $row): array
	{
		return ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'username' => (string) $row['username']];
	}
}
