<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Meta;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Upserts a 4SEO per-page meta override (#__forseo_custom_meta) for a single
 * Joomla page identified by content_id, joomla_params, or article_id.
 *
 * 4SEO stores meta data as a three-layer envelope inside the `data` JSON
 * column:
 *   - platform.* — values the source component reports natively (e.g.
 *     Joomla article's own title)
 *   - auto.*     — what 4SEO sniffed from the rendered page
 *   - custom.*   — what the user set in 4SEO admin
 *
 * Two SMALLINT status columns (`status_title`, `status_description`) tell
 * 4SEO's renderer which layer to use:
 *     0 = auto, 1 = platform, 2 = custom, 3 = none
 *
 * This tool sets values into `custom.*` and bumps the corresponding
 * `status_*` column to 2 (custom) for any field supplied. Other fields
 * and other layers are left alone. If the row doesn't exist yet, it's
 * inserted with empty platform/auto layers — 4SEO repopulates those on
 * the next crawl.
 */
final class SetMetaOverrideTool extends AbstractTool
{
	use ContentIdTrait;

	public function getName(): string { return 'set_4seo_meta_override'; }

	public function getDescription(): string
	{
		return 'Set per-page meta overrides on 4SEO for one Joomla page. Provide ONE of: '
			. 'content_id, joomla_params, or article_id. Then supply any of: title, description, '
			. 'robots, canonical. Each field supplied flips its 4SEO status to "custom" (2). '
			. 'Other fields and 4SEO\'s platform/auto layers are preserved untouched. Upserts: '
			. 'creates the row if missing, updates in place if present. VERIFICATION TIP: to '
			. 'confirm the override landed on the rendered page, use fetch_rendered_url with the '
			. 'page\'s SEF URL (e.g. "my-category/my-article-alias"), NOT the raw '
			. '"index.php?option=com_content&view=article&id=N" form. 4SEO matches custom meta '
			. 'by SEF URL and won\'t apply the override on the non-SEF form.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'content_id'    => ['type' => 'string'],
				'joomla_params' => ['type' => 'object'],
				'article_id'    => ['type' => 'integer'],
				'title'         => ['type' => 'string', 'description' => 'Custom <title> tag for the page.'],
				'description'   => ['type' => 'string', 'description' => 'Custom <meta name="description">.'],
				'robots'        => ['type' => 'string', 'description' => 'Custom <meta name="robots"> directive, e.g. "noindex,nofollow".'],
				'canonical'     => ['type' => 'string', 'description' => 'Custom canonical URL.'],
				'url'           => ['type' => 'string', 'description' => 'Optional URL stamped on the row (4SEO uses this as a secondary id for duplicate-URL handling).'],
				'enabled'       => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default 1 on insert. Pass 0 to disable the override without deleting it.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$contentId = $this->resolveContentId($arguments);

		// Gather which custom fields were supplied
		$customFields = [];
		foreach (['title', 'description', 'robots', 'canonical'] as $f) {
			if (array_key_exists($f, $arguments)) {
				$customFields[$f] = (string) $arguments[$f];
			}
		}
		if ($customFields === [] && !isset($arguments['enabled'])) {
			return ToolResult::error('Supply at least one of: title, description, robots, canonical, enabled.');
		}

		$fullTable = $this->db->getPrefix() . 'forseo_custom_meta';

		// Read existing row, if any
		$existsQuery = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'data', 'status_title', 'status_description', 'enabled', 'url']))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('content_id') . ' = ' . $this->db->quote($contentId));
		$existing = $this->db->setQuery($existsQuery)->loadAssoc();

		$now = gmdate('Y-m-d H:i:s');

		if ($existing) {
			$data = json_decode((string) ($existing['data'] ?? ''), true);
			if (!is_array($data)) { $data = []; }
			$data['custom'] = is_array($data['custom'] ?? null) ? $data['custom'] : [];

			foreach ($customFields as $f => $v) {
				$data['custom'][$f] = $v;
			}

			$statusTitle = isset($customFields['title'])
				? 2
				: (int) ($existing['status_title'] ?? 0);
			$statusDesc = isset($customFields['description'])
				? 2
				: (int) ($existing['status_description'] ?? 0);

			$enabled = isset($arguments['enabled'])
				? ((int) $arguments['enabled'] === 1 ? 1 : 0)
				: (int) ($existing['enabled'] ?? 1);

			$url = $arguments['url'] ?? $existing['url'] ?? '';

			$update = $this->db->getQuery(true)
				->update($this->db->quoteName($fullTable))
				->set($this->db->quoteName('data') . ' = ' . $this->db->quote(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
				->set($this->db->quoteName('status_title') . ' = ' . $statusTitle)
				->set($this->db->quoteName('status_description') . ' = ' . $statusDesc)
				->set($this->db->quoteName('enabled') . ' = ' . $enabled)
				->set($this->db->quoteName('url') . ' = ' . $this->db->quote((string) $url))
				->where($this->db->quoteName('id') . ' = ' . (int) $existing['id']);
			$this->db->setQuery($update)->execute();

			return ToolResult::json([
				'ok'         => true,
				'action'     => 'updated',
				'row_id'     => (int) $existing['id'],
				'content_id' => $contentId,
				'custom_set' => array_keys($customFields),
				'status_title'       => $statusTitle,
				'status_description' => $statusDesc,
				'enabled'    => $enabled === 1,
			]);
		}

		// Insert path — build a fresh data envelope with empty platform / auto
		// and the supplied custom fields. 4SEO repopulates platform & auto
		// on its next crawl of the page.
		$data = [
			'crawled_at'    => $now,
			'enabled'       => 1,
			'useTitle'      => 0,
			'useCanonical'  => 0,
			'useDescription' => 0,
			'useRobots'     => 0,
			'useImage'      => 0,
			'platform'      => [
				'title' => '', 'description' => '', 'robots' => '',
				'sharing_image' => '', 'image' => '', 'canonical' => '',
			],
			'meta_hash_platform' => '',
			'auto' => [
				'title' => '', 'description' => '', 'robots' => '',
				'sharing_image' => [], 'image' => [], 'canonical' => null,
			],
			'meta_hash_auto' => '',
			'custom' => array_merge(
				[
					'title' => '', 'description' => '', 'robots' => '',
					'sharing_image' => '', 'image' => '', 'canonical' => '',
				],
				$customFields
			),
			'meta_hash_custom' => '',
			'raw_content_head_top'    => '',
			'raw_content_head_bottom' => '',
			'raw_content_body_top'    => '',
			'raw_content_body_bottom' => '',
			'id' => 0,
		];

		$statusTitle = isset($customFields['title']) ? 2 : 0;
		$statusDesc  = isset($customFields['description']) ? 2 : 0;
		$enabled     = isset($arguments['enabled']) && (int) $arguments['enabled'] === 0 ? 0 : 1;
		$url         = (string) ($arguments['url'] ?? '');

		$insert = $this->db->getQuery(true)
			->insert($this->db->quoteName($fullTable))
			->columns($this->db->quoteName(['source', 'content_id', 'url', 'data', 'status_title', 'status_description', 'hash_title', 'hash_description', 'crawled_at', 'enabled']))
			->values(
				'0, '
				. $this->db->quote($contentId) . ', '
				. $this->db->quote($url) . ', '
				. $this->db->quote(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . ', '
				. $statusTitle . ', '
				. $statusDesc . ', '
				. $this->db->quote('') . ', '
				. $this->db->quote('') . ', '
				. $this->db->quote($now) . ', '
				. $enabled
			);
		$this->db->setQuery($insert)->execute();
		$id = (int) $this->db->insertid();

		return ToolResult::json([
			'ok'         => true,
			'action'     => 'inserted',
			'row_id'     => $id,
			'content_id' => $contentId,
			'custom_set' => array_keys($customFields),
			'status_title'       => $statusTitle,
			'status_description' => $statusDesc,
			'enabled'    => $enabled === 1,
		]);
	}
}
