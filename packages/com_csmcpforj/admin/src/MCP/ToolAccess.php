<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\User\User;

/**
 * The tool set a user's groups allow, from Options → Tool access.
 *
 * Each rule names one user group, the tool domains its members may use
 * ("articles", "media", an add-on's domain...), optional single tools from
 * other domains, and whether the rule is read-only. Asked for by an agency
 * running a town hall site, where staff post news from Claude or ChatGPT and
 * should see the content and media tools only, never users, extensions or
 * configuration (Michel Campaner, Netizis, 2026-11-20).
 *
 * How rules combine:
 *   - Rules apply to the groups a user is directly in. Joomla puts most users
 *     in Registered as well, so "a group without a rule means no limit" would
 *     make every rule toothless: a user is limited as soon as ONE of their
 *     groups has a rule, and then gets the union of the rules that match.
 *   - A domain or tool is writable when any matching rule that lists it is
 *     not read-only.
 *   - Super Users are never limited, so a rule can't lock the owner out.
 *   - No rule for any of the user's groups: no limit, exactly as before 2.14.0.
 *
 * This only narrows what MCP for J offers. The user still needs Use / Write
 * in MCP for J's Permissions, and each tool still checks Joomla's own
 * permissions for the item it touches.
 */
final class ToolAccess
{
	/**
	 * @param array<string, bool> $domains Domain slug => writable
	 * @param array<string, bool> $tools   Tool name => writable
	 * @param array<int, string>  $groups  Titles of the groups whose rules matched, for messages
	 */
	private function __construct(
		private readonly array $domains,
		private readonly array $tools,
		private readonly array $groups
	) {}

	/** The limits for this user, or null when nothing limits them. */
	public static function forUser(User $user): ?self
	{
		if ($user->guest || $user->authorise('core.admin')) {
			return null;
		}

		$userGroups = array_map('intval', array_values((array) $user->groups));

		if ($userGroups === []) {
			return null;
		}

		$domains = [];
		$tools   = [];
		$matched = [];

		foreach (self::rules() as $rule) {
			if (!in_array($rule['group'], $userGroups, true)) {
				continue;
			}

			$matched[$rule['group']] = true;
			$writable = !$rule['read_only'];

			foreach ($rule['domains'] as $domain) {
				$domains[$domain] = ($domains[$domain] ?? false) || $writable;
			}

			foreach ($rule['tools'] as $tool) {
				$tools[$tool] = ($tools[$tool] ?? false) || $writable;
			}
		}

		if ($matched === []) {
			return null;
		}

		return new self($domains, $tools, self::groupTitles(array_keys($matched)));
	}

	/**
	 * The saved rules, normalised. A rule with no group, or with neither a
	 * domain nor a tool, is skipped: an empty rule would otherwise hide every
	 * tool from that group, which is never what someone saving half a row means.
	 *
	 * @return array<int, array{group: int, domains: string[], tools: string[], read_only: bool}>
	 */
	public static function rules(): array
	{
		$raw = ComponentHelper::getParams('com_csmcpforj')->get('tool_access_rules');

		if (is_string($raw)) {
			$raw = json_decode($raw, true);
		}

		$rules = [];

		foreach ((array) $raw as $row) {
			$row   = (array) $row;
			$group = (int) ($row['group'] ?? 0);

			$domains = array_values(array_unique(array_filter(array_map(
				static fn ($d): string => strtolower(trim((string) $d)),
				is_array($row['domains'] ?? null) ? $row['domains'] : explode(',', (string) ($row['domains'] ?? ''))
			))));

			$tools = array_values(array_unique(array_filter(array_map(
				static fn ($t): string => strtolower(trim((string) $t)),
				preg_split('/[\s,]+/', (string) ($row['tools'] ?? '')) ?: []
			))));

			if ($group <= 0 || ($domains === [] && $tools === [])) {
				continue;
			}

			$rules[] = [
				'group'     => $group,
				'domains'   => $domains,
				'tools'     => $tools,
				'read_only' => (bool) (int) ($row['read_only'] ?? 0),
			];
		}

		return $rules;
	}

	public function allows(ToolInterface $tool): bool
	{
		$name   = strtolower($tool->getName());
		$domain = strtolower($tool->getCategory());

		if (!isset($this->tools[$name]) && !isset($this->domains[$domain])) {
			return false;
		}

		if (!self::isWriteTool($tool)) {
			return true;
		}

		return ($this->tools[$name] ?? false) || ($this->domains[$domain] ?? false);
	}

	/** Why a tool was refused, for the tools/call error. */
	public function refusal(ToolInterface $tool): string
	{
		$name   = strtolower($tool->getName());
		$domain = strtolower($tool->getCategory());
		$listed = isset($this->tools[$name]) || isset($this->domains[$domain]);

		return 'Tool "' . $tool->getName() . '" is not in the tool set for your user group ('
			. implode(', ', $this->groups) . ')'
			. ($listed ? ': that group may only read with it, not change anything.' : '.')
			. ' A Super User can change this in Components → MCP for Joomla → Options → Tool access.';
	}

	/**
	 * A tool counts as a write when it needs MCP for J's Write permission or
	 * does not declare itself read-only. Either signal is enough: a "get" tool
	 * that creates something on first use is a read permission but not
	 * read-only, and must not slip through a read-only rule.
	 */
	private static function isWriteTool(ToolInterface $tool): bool
	{
		return $tool->getRequiredPermission() === 'write' || empty($tool->getMcpAnnotations()['readOnlyHint']);
	}

	/**
	 * @param int[] $ids
	 * @return string[]
	 */
	private static function groupTitles(array $ids): array
	{
		try {
			$db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

			$titles = (array) $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName('title'))
					->from($db->quoteName('#__usergroups'))
					->whereIn($db->quoteName('id'), $ids)
					->order($db->quoteName('title'))
			)->loadColumn();

			return $titles !== [] ? $titles : array_map('strval', $ids);
		} catch (\Throwable $e) {
			return array_map('strval', $ids);
		}
	}
}
