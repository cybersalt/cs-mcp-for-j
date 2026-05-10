<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Convenience wrapper around set_article_schema for the Custom type. Pass a
 * full JSON-LD object (e.g. an FAQPage with mainEntity[]) and we wrap it in
 * the {schemaType: Custom, payload: {@type: Custom, json: <stringified>}}
 * shape Joomla's plg_schemaorg_custom expects. Use this for any type Joomla
 * does not ship a native form for: FAQPage, Service, LocalBusiness, Product,
 * Review, BreadcrumbList, etc.
 */
final class SetArticleCustomJsonldTool extends AbstractTool
{
	public function getName(): string { return 'set_article_custom_jsonld'; }

	public function getDescription(): string
	{
		return 'Attach a fully-formed JSON-LD object as Custom schema to a content item. Required: '
			. 'item_id, jsonld (the @context+@type+rest object). Use this for FAQPage, Service, '
			. 'LocalBusiness, Product, Review, BreadcrumbList, or any other schema.org type Joomla '
			. 'does not ship a native form for. The object is stringified into the Custom plugin\'s '
			. '"json" field automatically.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['item_id', 'jsonld'],
			'properties' => [
				'item_id' => ['type' => 'integer'],
				'context' => ['type' => 'string', 'description' => 'Default "com_content.article".'],
				'jsonld'  => ['type' => 'object', 'description' => 'Complete JSON-LD object, e.g. {"@context":"https://schema.org","@type":"FAQPage","mainEntity":[...]}'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$itemId  = $this->requirePositiveInt($arguments, 'item_id');
		$context = (string) ($arguments['context'] ?? 'com_content.article');
		$jsonld  = $arguments['jsonld'] ?? null;

		if (!is_array($jsonld) || empty($jsonld)) {
			return ToolResult::error('jsonld must be a non-empty JSON object.');
		}

		// Add @context if missing — most Custom JSON-LD includes it
		if (!isset($jsonld['@context'])) {
			$jsonld['@context'] = 'https://schema.org';
		}

		$payload = [
			'@type' => 'Custom',
			'json'  => json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		];

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
				->set($this->db->quoteName('schemaType') . ' = ' . $this->db->quote('Custom'))
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
					. $this->db->quote('Custom') . ', '
					. $this->db->quote($schemaJson)
				);
			$this->db->setQuery($insert)->execute();
			$existingId = (int) $this->db->insertid();
			$action     = 'inserted';
		}

		return ToolResult::json([
			'ok'             => true,
			'action'         => $action,
			'row_id'         => $existingId,
			'item_id'        => $itemId,
			'context'        => $context,
			'schema_type'    => 'Custom',
			'jsonld_type'    => $jsonld['@type'] ?? null,
		]);
	}
}
