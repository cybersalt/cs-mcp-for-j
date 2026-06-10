<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Bulk variant of set_article_custom_jsonld. Apply a JSON-LD payload to many
 * articles in a single tool call instead of N round-trips. Each item is
 * processed independently — one failure doesn't roll back the others. The
 * response reports per-item ok/error so the agent can see what landed and
 * what didn't.
 *
 * Capped at 500 updates per call to keep the request payload reasonable.
 * For larger batches the agent should chunk and call again.
 */
final class SetArticleCustomJsonldBulkTool extends AbstractTool
{
	private const MAX_UPDATES_PER_CALL = 500;

	public function getName(): string { return 'set_article_custom_jsonld_bulk'; }

	public function getDescription(): string
	{
		return 'Bulk attach Custom JSON-LD to many articles in one call. REQUIRED ARG: updates[] '
			. 'where each entry is {item_id (or article_id alias), jsonld, context?}. Per-item '
			. 'independent — one failure does not abort the rest. Response gives per-item ok/error. '
			. 'Cap: ' . self::MAX_UPDATES_PER_CALL . ' updates per call. For more, chunk and call again. '
			. 'IMPORTANT: each `jsonld` must be a SINGLE object (e.g. {"@type":"VideoObject",...}); '
			. 'do NOT wrap it in your own @graph — Joomla merges each block into the page\'s '
			. 'existing @graph automatically.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['updates'],
			'properties' => [
				'updates' => [
					'type'     => 'array',
					'maxItems' => self::MAX_UPDATES_PER_CALL,
					'items' => [
						'type'     => 'object',
						'required' => ['jsonld'],
						'properties' => [
							'item_id'    => ['type' => 'integer', 'description' => 'Article id. Pass this OR article_id.'],
							'article_id' => ['type' => 'integer', 'description' => 'Alias for item_id.'],
							'context'    => ['type' => 'string', 'description' => 'Default "com_content.article".'],
							'jsonld'     => ['type' => 'object'],
						],
					],
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$updates = $arguments['updates'] ?? null;
		if (!is_array($updates) || $updates === []) {
			return ToolResult::error('updates must be a non-empty array.');
		}
		if (count($updates) > self::MAX_UPDATES_PER_CALL) {
			return ToolResult::error('updates exceeds cap of ' . self::MAX_UPDATES_PER_CALL . '. Chunk and retry.');
		}

		$results       = [];
		$inserted      = 0;
		$updatedCount  = 0;
		$failed        = 0;

		foreach ($updates as $i => $update) {
			if (!is_array($update)) {
				$results[] = ['index' => $i, 'ok' => false, 'error' => 'Each update must be an object.'];
				$failed++;
				continue;
			}
			$itemId  = (int) ($update['item_id'] ?? $update['article_id'] ?? 0);
			$context = (string) ($update['context'] ?? 'com_content.article');
			$jsonld  = $update['jsonld'] ?? null;

			if ($itemId <= 0) {
				$results[] = ['index' => $i, 'ok' => false, 'error' => 'item_id (or article_id alias) must be positive.'];
				$failed++;
				continue;
			}
			if (!is_array($jsonld) || $jsonld === []) {
				$results[] = ['index' => $i, 'item_id' => $itemId, 'ok' => false, 'error' => 'jsonld must be a non-empty object.'];
				$failed++;
				continue;
			}

			if (!isset($jsonld['@context'])) {
				$jsonld['@context'] = 'https://schema.org';
			}

			$payload = [
				'@type' => 'Custom',
				'json'  => json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			];
			$schemaJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

			try {
				$existsQuery = $this->db->getQuery(true)
					->select($this->db->quoteName('id'))
					->from($this->db->quoteName('#__schemaorg'))
					->where($this->db->quoteName('itemId') . ' = ' . $itemId)
					->where($this->db->quoteName('context') . ' = ' . $this->db->quote($context));
				$existingId = (int) $this->db->setQuery($existsQuery)->loadResult();

				if ($existingId > 0) {
					$update = $this->db->getQuery(true)
						->update($this->db->quoteName('#__schemaorg'))
						->set($this->db->quoteName('schemaType') . ' = ' . $this->db->quote('Custom'))
						->set($this->db->quoteName('schema') . ' = ' . $this->db->quote($schemaJson))
						->where($this->db->quoteName('id') . ' = ' . $existingId);
					$this->db->setQuery($update)->execute();
					$results[] = [
						'index'   => $i,
						'item_id' => $itemId,
						'ok'      => true,
						'action'  => 'updated',
						'row_id'  => $existingId,
					];
					$updatedCount++;
				} else {
					$insert = $this->db->getQuery(true)
						->insert($this->db->quoteName('#__schemaorg'))
						->columns($this->db->quoteName(['itemId', 'context', 'schemaType', 'schema']))
						->values(
							$itemId . ', '
							. $this->db->quote($context) . ', '
							. $this->db->quote('Custom') . ', '
							. $this->db->quote($schemaJson)
						);
					$this->db->setQuery($insert)->execute();
					$newId = (int) $this->db->insertid();
					$results[] = [
						'index'   => $i,
						'item_id' => $itemId,
						'ok'      => true,
						'action'  => 'inserted',
						'row_id'  => $newId,
					];
					$inserted++;
				}
			} catch (\Throwable $e) {
				$results[] = [
					'index'   => $i,
					'item_id' => $itemId,
					'ok'      => false,
					'error'   => $e->getMessage(),
				];
				$failed++;
			}
		}

		return ToolResult::json([
			'ok'         => $failed === 0,
			'attempted'  => count($updates),
			'inserted'   => $inserted,
			'updated'    => $updatedCount,
			'failed'     => $failed,
			'results'    => $results,
		]);
	}
}
