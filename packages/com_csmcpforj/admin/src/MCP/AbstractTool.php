<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Convenience base for ToolInterface implementations.
 *
 * Wraps each call in a try/catch that returns a uniform ToolResult::error()
 * so individual tools don't have to repeat boilerplate. Provides helpers for
 * booting other components and grabbing their MVCFactory / Administrator
 * models — the safest way to write data into core Joomla tables.
 */
abstract class AbstractTool implements ToolInterface
{
	public function __construct(protected readonly DatabaseInterface $db) {}

	final public function execute(array $arguments, User $actor): ToolResult
	{
		try {
			return $this->run($arguments, $actor);
		} catch (Throwable $e) {
			return ToolResult::error($this->getName() . ' failed: ' . $e->getMessage());
		}
	}

	abstract protected function run(array $arguments, User $actor): ToolResult;

	/**
	 * Derives a category from the tool's PHP namespace by default. Tools
	 * declared in `Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\…`
	 * return "articles"; `Tools\JoomlaUpdate\…` returns "joomla_update".
	 * Add-on plugins follow the same pattern in their own namespaces
	 * (Tools\ForSEO → "forseo", Tools\AkeebaBackup → "akeeba_backup").
	 *
	 * Override in a concrete tool to force a specific category string.
	 */
	public function getCategory(): string
	{
		$ns    = static::class;
		$parts = explode('\\', $ns);

		// Find the "Tools" segment; the next segment is the domain folder.
		$idx = array_search('Tools', $parts, true);
		if ($idx === false || !isset($parts[$idx + 1])) {
			return 'general';
		}

		// PascalCase → snake_case: JoomlaUpdate → joomla_update,
		// AkeebaBackup → akeeba_backup.
		$domain = (string) $parts[$idx + 1];
		$snake  = (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $domain);
		return strtolower($snake);
	}

	/**
	 * Default annotation hint set, derived from the tool's name. Subclasses can
	 * override to be explicit (e.g., a tool that LOOKS like a list but actually
	 * mutates state should declare destructiveHint=true directly).
	 *
	 * Auto-classification rules — matched in order, first match wins:
	 *   list_* / get_* / check_* / validate_* / fetch_*
	 *     → readOnlyHint=true, destructiveHint=false, idempotentHint=true
	 *   delete_* / uninstall_* / clear_* / revoke_* / cancel_*
	 *     → readOnlyHint=false, destructiveHint=true, idempotentHint=true
	 *   create_*
	 *     → readOnlyHint=false, destructiveHint=false, idempotentHint=false
	 *   update_* / set_* / enable_* / disable_* / reset_* / install_*
	 *     → readOnlyHint=false, destructiveHint=false, idempotentHint=true
	 *   anything else
	 *     → readOnlyHint=false, destructiveHint=false (treat as write but
	 *       unknown, safest default for the read-only-mode filter)
	 *
	 * @return array<string, bool|string>
	 */
	public function getMcpAnnotations(): array
	{
		$name = $this->getName();

		// Order matters: more specific prefixes first so e.g. "list_*" doesn't
		// also match a hypothetical "list_and_delete_*" tool — explicit branch
		// for destructive verbs comes first.
		$prefixIs = static fn(string $p): bool => str_starts_with($name, $p);

		if ($prefixIs('delete_') || $prefixIs('uninstall_') || $prefixIs('clear_')
			|| $prefixIs('revoke_') || $prefixIs('cancel_')) {
			return [
				'readOnlyHint'    => false,
				'destructiveHint' => true,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			];
		}

		if ($prefixIs('list_') || $prefixIs('get_') || $prefixIs('check_')
			|| $prefixIs('validate_') || $prefixIs('fetch_')) {
			return [
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => $prefixIs('fetch_'),
			];
		}

		if ($prefixIs('create_')) {
			return [
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => false,
				'openWorldHint'   => false,
			];
		}

		if ($prefixIs('update_') || $prefixIs('set_') || $prefixIs('enable_')
			|| $prefixIs('disable_') || $prefixIs('reset_') || $prefixIs('install_')) {
			return [
				'readOnlyHint'    => false,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => $prefixIs('install_'),
			];
		}

		// Unknown verb — assume write, non-destructive. Safe default for the
		// read-only-mode filter (will be excluded as "not read-only").
		return [
			'readOnlyHint'    => false,
			'destructiveHint' => false,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		];
	}

	protected function app(): CMSApplication
	{
		return Factory::getApplication();
	}

	protected function bootComponent(string $component): ComponentInterface
	{
		return $this->app()->bootComponent($component);
	}

	/**
	 * Returns an Administrator model from another component, with the request-
	 * coupling disabled so it doesn't pull state from the live request.
	 *
	 * Registers the target component's admin form + field paths BEFORE booting.
	 * This is critical when MCP runs through the API app (api/index.php):
	 * JPATH_COMPONENT resolves to the API entrypoint, so AdminModel::getForm()
	 * fails with "Form::loadForm could not load file" when post-save hooks
	 * (workflow, contenthistory, fields) ask for the admin form. The row is
	 * already INSERTed by then, so save() returns false but the data persists
	 * — silently spawning duplicates on retry. Registering the paths up front
	 * lets getForm() resolve normally and avoids the false-failure entirely.
	 */
	protected function getModel(string $component, string $name, string $client = 'Administrator'): object
	{
		$adminBase = JPATH_ADMINISTRATOR . '/components/' . $component;
		if (is_dir($adminBase . '/forms')) {
			FormHelper::addFormPath($adminBase . '/forms');
		}
		if (is_dir($adminBase . '/fields')) {
			FormHelper::addFieldPath($adminBase . '/fields');
		}

		return $this->bootComponent($component)->getMVCFactory()
			->createModel($name, $client, ['ignore_request' => true]);
	}

	protected function requireString(array $arguments, string $key): string
	{
		$value = trim((string) ($arguments[$key] ?? ''));
		if ($value === '') {
			throw new \InvalidArgumentException($key . ' is required.');
		}
		return $value;
	}

	protected function requirePositiveInt(array $arguments, string $key): int
	{
		$value = (int) ($arguments[$key] ?? 0);
		if ($value <= 0) {
			throw new \InvalidArgumentException($key . ' is required and must be a positive integer.');
		}
		return $value;
	}

	/**
	 * Pull a positive content-item id from arguments, accepting either the
	 * canonical Joomla "item_id" name (matches #__schemaorg.itemId) or the
	 * natural "article_id" alias.
	 *
	 * Tools named *_article_* (set_article_schema, get_article_schema, ...)
	 * are easy for an agent to confuse with the *_article tools (update_article,
	 * delete_article), which use "id" / "article_id". Accept both so the
	 * agent's first guess works either way.
	 *
	 * @param array<string,mixed> $arguments
	 */
	protected function requireItemOrArticleId(array $arguments): int
	{
		$id = (int) ($arguments['item_id'] ?? $arguments['article_id'] ?? 0);
		if ($id <= 0) {
			throw new \InvalidArgumentException(
				'item_id is required and must be a positive integer. '
				. '(article_id is accepted as an alias for convenience.)'
			);
		}
		return $id;
	}

	/**
	 * Validate that an asset name follows the safe Joomla shape, rejecting any
	 * input that could traverse the filesystem when concatenated into a path.
	 *
	 * Accepts:
	 *   - "root.1" (literal — the global root asset)
	 *   - "com_<lowercase alphanumeric and underscore>" (a component asset)
	 *   - "com_<...>.<subtype>.<id>" (sub-asset, e.g. com_content.article.42)
	 *
	 * Rejects anything containing path separators, "..", or any character
	 * outside the small allowlist. Tools that take a component / asset name
	 * from input MUST call this before using the value in any filesystem
	 * operation. SQL paths are already safe via $db->quote(), but defense in
	 * depth is cheap.
	 */
	protected function requireSafeAssetName(string $name): string
	{
		$trimmed = trim($name);
		if ($trimmed === '') {
			throw new \InvalidArgumentException('Asset name is required.');
		}
		if ($trimmed === 'root.1') {
			return $trimmed;
		}
		if (preg_match('/^com_[a-z0-9_]+(\.[a-z0-9_]+\.\d+)?$/', $trimmed) !== 1) {
			throw new \InvalidArgumentException(
				'Invalid asset name "' . $trimmed . '". Expected "root.1", "com_<name>", '
				. 'or "com_<name>.<subtype>.<id>".'
			);
		}
		return $trimmed;
	}

	/**
	 * Refuse the operation if the actor cannot edit the target user under
	 * Joomla's privilege-escalation rule: you cannot edit a user whose highest
	 * group level is greater than or equal to your own.
	 *
	 * This mirrors what Joomla's UserModel does internally for update_user.
	 * The direct-#__user_profiles tools (token tools) bypass UserModel, so
	 * they must enforce this themselves. Without this check, a Manager-level
	 * MCP user could mint a token for a Super User and pivot to full access.
	 *
	 * Super Users (group with core.admin on root.1) can always edit anyone.
	 */
	protected function requireCanEditTargetUser(User $actor, int $targetUserId): void
	{
		if ((int) $actor->id === $targetUserId) {
			return;
		}

		if ($actor->authorise('core.admin')) {
			return;
		}

		$actorMaxLevel  = $this->maxGroupLevel(Access::getGroupsByUser((int) $actor->id, false) ?: []);
		$targetMaxLevel = $this->maxGroupLevel(Access::getGroupsByUser($targetUserId, false) ?: []);

		if ($targetMaxLevel >= $actorMaxLevel) {
			throw new \RuntimeException(
				'Permission denied: cannot operate on user ' . $targetUserId
				. ' (target has equal-or-higher privileges than you). Only Super Users can.'
			);
		}
	}

	/**
	 * Returns the maximum nesting level across the supplied group ids. Higher
	 * level = deeper in the hierarchy = more privileges in Joomla's model.
	 *
	 * @param array<int, int> $groupIds
	 */
	private function maxGroupLevel(array $groupIds): int
	{
		if (empty($groupIds)) {
			return 0;
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$q  = $db->getQuery(true)
			->select('MAX(' . $db->quoteName('level') . ')')
			->from($db->quoteName('#__usergroups'))
			->whereIn($db->quoteName('id'), array_map('intval', $groupIds));

		return (int) $db->setQuery($q)->loadResult();
	}

	/**
	 * Save data through a Joomla AdminModel and report the truthful outcome.
	 *
	 * Joomla's AdminModel::save() returns false when ANY plugin in the
	 * onContentBeforeSave / onContentAfterSave chain throws — workflow,
	 * contenthistory, fields, finder, etc. — EVEN IF $table->store() already
	 * INSERTed the row. Trusting save()'s return value causes false-failure
	 * reports, and an MCP agent will then retry the create, silently spawning
	 * duplicates on every "failed" call.
	 *
	 * The id assigned to model state is the truthful signal: positive means
	 * the row exists in the database, regardless of what post-save warnings
	 * fired afterward.
	 *
	 * @return array{ok: bool, id: int, error: string}
	 */
	protected function saveAdminModel(object $model, array $data): array
	{
		$ok = (bool) $model->save($data);

		$idStateKey = method_exists($model, 'getName') ? $model->getName() . '.id' : 'id';
		$id         = (int) $model->getState($idStateKey);
		$error      = method_exists($model, 'getError') ? (string) ($model->getError() ?: '') : '';

		return ['ok' => $ok, 'id' => $id, 'error' => $error];
	}
}
