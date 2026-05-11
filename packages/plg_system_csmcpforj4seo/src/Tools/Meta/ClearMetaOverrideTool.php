<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Meta;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Two modes:
 *
 *   - reset_to_auto (default): keeps the row in #__forseo_custom_meta but
 *     clears the custom layer and resets status_title / status_description
 *     to 0 (auto). 4SEO will fall back to its auto-detected values for
 *     this page.
 *
 *   - delete_row: hard-removes the row entirely. Use when you genuinely
 *     want 4SEO to forget the page ever had an override (subsequent crawls
 *     will recreate the row if 4SEO encounters the page again).
 */
final class ClearMetaOverrideTool extends AbstractTool
{
	use ContentIdTrait;

	public function getName(): string { return 'clear_4seo_meta_override'; }

	public function getDescription(): string
	{
		return 'Clear the 4SEO custom meta on one page. By default (mode="reset_to_auto") '
			. 'keeps the row but wipes the custom layer and sets status flags back to 0 '
			. '(auto), so 4SEO falls back to its sniffed values. Pass mode="delete_row" '
			. 'to hard-remove the row entirely (4SEO will recreate it on its next crawl '
			. 'if it encounters the page).';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'content_id'    => ['type' => 'string'],
				'joomla_params' => ['type' => 'object'],
				'article_id'    => ['type' => 'integer'],
				'mode'          => ['type' => 'string', 'enum' => ['reset_to_auto', 'delete_row'], 'description' => 'Default reset_to_auto.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$contentId = $this->resolveContentId($arguments);
		$mode      = (string) ($arguments['mode'] ?? 'reset_to_auto');
		if (!in_array($mode, ['reset_to_auto', 'delete_row'], true)) {
			return ToolResult::error('mode must be reset_to_auto or delete_row.');
		}

		$fullTable = $this->db->getPrefix() . 'forseo_custom_meta';

		$existsQuery = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'data']))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('content_id') . ' = ' . $this->db->quote($contentId));
		$existing = $this->db->setQuery($existsQuery)->loadAssoc();

		if (!$existing) {
			return ToolResult::json([
				'ok'         => true,
				'action'     => 'noop',
				'content_id' => $contentId,
				'message'    => 'No row found for this content_id; nothing to clear.',
			]);
		}

		if ($mode === 'delete_row') {
			$delete = $this->db->getQuery(true)
				->delete($this->db->quoteName($fullTable))
				->where($this->db->quoteName('id') . ' = ' . (int) $existing['id']);
			$this->db->setQuery($delete)->execute();
			return ToolResult::json([
				'ok'         => true,
				'action'     => 'deleted',
				'row_id'     => (int) $existing['id'],
				'content_id' => $contentId,
			]);
		}

		// reset_to_auto: keep platform + auto layers, clear custom, reset statuses
		$data = json_decode((string) ($existing['data'] ?? ''), true);
		if (!is_array($data)) { $data = []; }
		$data['custom'] = [
			'title' => '', 'description' => '', 'robots' => '',
			'sharing_image' => '', 'image' => '', 'canonical' => '',
		];

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName($fullTable))
			->set($this->db->quoteName('data') . ' = ' . $this->db->quote(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
			->set($this->db->quoteName('status_title') . ' = 0')
			->set($this->db->quoteName('status_description') . ' = 0')
			->where($this->db->quoteName('id') . ' = ' . (int) $existing['id']);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'         => true,
			'action'     => 'reset_to_auto',
			'row_id'     => (int) $existing['id'],
			'content_id' => $contentId,
		]);
	}
}
