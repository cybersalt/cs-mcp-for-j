<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Insert/update the #__schemaorg row for a content item. Storage shape matches
 * what plg_system_schemaorg's onContentAfterSave hook would produce: schemaType
 * is the type label, schema column is the JSON-encoded type-specific payload.
 *
 * For schemaType=Custom, prefer set_article_custom_jsonld — it accepts a JSON
 * object instead of a stringified JSON inside a string field.
 *
 * For schemaType=None, use clear_article_schema (it deletes the row, matching
 * Joomla's deleteSchemaOrg() behaviour).
 */
final class SetArticleSchemaTool extends AbstractTool
{
	private const VALID_TYPES = ['Article', 'BlogPosting', 'Book', 'Custom', 'Event', 'JobPosting', 'Organization', 'Person', 'Recipe'];

	public function getName(): string { return 'set_article_schema'; }

	public function getDescription(): string
	{
		return 'Set/replace the Schema.org data for a content item. '
			. 'REQUIRED ARG: item_id (the article id; article_id is also accepted as a convenience alias). '
			. 'REQUIRED ARG: schema_type — one of: Article, BlogPosting, Book, Custom, Event, JobPosting, '
			. 'Organization, Person, Recipe. REQUIRED ARG: payload — the type-specific object '
			. '(use list_schema_types to see typical fields per type). Use clear_article_schema to remove. '
			. 'For Custom, prefer set_article_custom_jsonld so you can pass a JSON object directly.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['schema_type', 'payload'],
			'properties' => [
				'item_id'     => ['type' => 'integer', 'description' => 'Article id (or other entity id matching context). Pass this OR article_id.'],
				'article_id'  => ['type' => 'integer', 'description' => 'Alias for item_id; use whichever name feels natural.'],
				'context'     => ['type' => 'string', 'description' => 'Default "com_content.article".'],
				'schema_type' => ['type' => 'string', 'enum' => self::VALID_TYPES],
				'payload'     => ['type' => 'object', 'description' => 'Type-specific payload. Will be JSON-encoded into the schema column.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$itemId     = $this->requireItemOrArticleId($arguments);
		$context    = (string) ($arguments['context'] ?? 'com_content.article');
		$schemaType = (string) ($arguments['schema_type'] ?? '');

		if (!in_array($schemaType, self::VALID_TYPES, true)) {
			return ToolResult::error('schema_type must be one of: ' . implode(', ', self::VALID_TYPES) . '. Use clear_article_schema to remove.');
		}

		$payload = $arguments['payload'] ?? null;
		if (!is_array($payload)) {
			return ToolResult::error('payload must be a JSON object.');
		}

		// Ensure @type matches schemaType — Joomla's renderer reads it back as a Registry
		// and the @type field is what schema.org consumers (Google, etc.) actually look at.
		if (!isset($payload['@type'])) {
			$payload['@type'] = $schemaType;
		}

		$schemaJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		$existsQuery = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__schemaorg'))
			->where($this->db->quoteName('itemId') . ' = ' . $itemId)
			->where($this->db->quoteName('context') . ' = ' . $this->db->quote($context));
		$existingId = (int) $this->db->setQuery($existsQuery)->loadResult();

		if ($existingId > 0) {
			$update = $this->db->getQuery(true)
				->update($this->db->quoteName('#__schemaorg'))
				->set($this->db->quoteName('schemaType') . ' = ' . $this->db->quote($schemaType))
				->set($this->db->quoteName('schema') . ' = ' . $this->db->quote($schemaJson))
				->where($this->db->quoteName('id') . ' = ' . $existingId);
			$this->db->setQuery($update)->execute();
			$action = 'updated';
		} else {
			$insert = $this->db->getQuery(true)
				->insert($this->db->quoteName('#__schemaorg'))
				->columns($this->db->quoteName(['itemId', 'context', 'schemaType', 'schema']))
				->values(
					$itemId . ', '
					. $this->db->quote($context) . ', '
					. $this->db->quote($schemaType) . ', '
					. $this->db->quote($schemaJson)
				);
			$this->db->setQuery($insert)->execute();
			$existingId = (int) $this->db->insertid();
			$action     = 'inserted';
		}

		return ToolResult::json([
			'ok'          => true,
			'action'      => $action,
			'row_id'      => $existingId,
			'item_id'     => $itemId,
			'context'     => $context,
			'schema_type' => $schemaType,
			'payload'     => $payload,
		]);
	}
}
