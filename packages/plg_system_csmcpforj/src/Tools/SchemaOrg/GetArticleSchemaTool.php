<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads the #__schemaorg row for a given (item_id, context). Returns null
 * when no row exists. context defaults to "com_content.article" since that's
 * the most common case; pass other contexts (e.g. "com_contact.contact") if
 * Joomla supports schemaorg there in the running version.
 */
final class GetArticleSchemaTool extends AbstractTool
{
	public function getName(): string { return 'get_article_schema'; }

	public function getDescription(): string
	{
		return 'Fetch the stored Schema.org data for a content item. Required: item_id. '
			. 'Returns schema_type, payload (decoded JSON), and the raw schema column. '
			. 'Returns null payload when no schemaorg row exists for the item.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['item_id'],
			'properties' => [
				'item_id' => ['type' => 'integer', 'description' => 'Article id (or other entity id matching context).'],
				'context' => ['type' => 'string', 'description' => 'Default "com_content.article". Other examples: "com_contact.contact".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$itemId  = $this->requirePositiveInt($arguments, 'item_id');
		$context = (string) ($arguments['context'] ?? 'com_content.article');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'itemId', 'context', 'schemaType', 'schema']))
			->from($this->db->quoteName('#__schemaorg'))
			->where($this->db->quoteName('itemId') . ' = ' . $itemId)
			->where($this->db->quoteName('context') . ' = ' . $this->db->quote($context));

		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::json([
				'item_id'    => $itemId,
				'context'    => $context,
				'schema_type' => null,
				'payload'    => null,
				'message'    => 'No schemaorg row exists for this item. Use set_article_schema or set_article_custom_jsonld to add one.',
			]);
		}

		$payload = null;
		if (!empty($row['schema'])) {
			$decoded = json_decode((string) $row['schema'], true);
			$payload = is_array($decoded) ? $decoded : null;
		}

		return ToolResult::json([
			'item_id'     => (int) $row['itemId'],
			'context'     => $row['context'],
			'schema_type' => $row['schemaType'],
			'payload'     => $payload,
			'raw_schema'  => $row['schema'],
			'row_id'      => (int) $row['id'],
		]);
	}
}
