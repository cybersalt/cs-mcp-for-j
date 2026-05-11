<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Meta;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads a single #__forseo_custom_meta row by content_id, joomla_params,
 * or article_id. Returns the row with the data JSON pre-decoded and the
 * three layers (platform / auto / custom) surfaced as separate fields so
 * the agent doesn't have to walk the envelope.
 */
final class GetMetaOverrideTool extends AbstractTool
{
	use ContentIdTrait;

	public function getName(): string { return 'get_4seo_meta_override'; }

	public function getDescription(): string
	{
		return 'Fetch the 4SEO per-page meta override row for one page. Provide ONE of: '
			. 'content_id (raw string like "id=42&option=com_content&view=article"), '
			. 'joomla_params (object with option/view/id/Itemid — the tool alphabetises and '
			. 'concatenates), or article_id (integer shorthand for a com_content article). '
			. 'Returns the platform / auto / custom layers separately, plus the status flags '
			. 'that tell 4SEO which layer to render. Null payload if no override row exists.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'content_id' => ['type' => 'string'],
				'joomla_params' => [
					'type' => 'object',
					'description' => 'Joomla URL query params, e.g. {"option":"com_content","view":"article","id":42}. Tool sorts keys case-sensitive ASCII before joining with &.',
				],
				'article_id' => ['type' => 'integer', 'description' => 'Shorthand: this is a com_content article.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$contentId = $this->resolveContentId($arguments);

		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName($this->db->getPrefix() . 'forseo_custom_meta'))
			->where($this->db->quoteName('content_id') . ' = ' . $this->db->quote($contentId));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::json([
				'content_id' => $contentId,
				'exists'     => false,
				'message'    => 'No #__forseo_custom_meta row for this content_id. Use set_4seo_meta_override to create one.',
			]);
		}

		$decoded = json_decode((string) ($row['data'] ?? ''), true);
		if (!is_array($decoded)) { $decoded = []; }

		return ToolResult::json([
			'content_id'         => $contentId,
			'exists'             => true,
			'row_id'             => (int) $row['id'],
			'source'             => (int) $row['source'],
			'url'                => $row['url'],
			'enabled'            => (int) $row['enabled'] === 1,
			'status_title'       => (int) $row['status_title'],
			'status_description' => (int) $row['status_description'],
			'status_legend'      => [0 => 'auto', 1 => 'platform', 2 => 'custom', 3 => 'none'],
			'platform'           => $decoded['platform'] ?? null,
			'auto'               => $decoded['auto'] ?? null,
			'custom'             => $decoded['custom'] ?? null,
			'crawled_at'         => $row['crawled_at'],
		]);
	}
}
