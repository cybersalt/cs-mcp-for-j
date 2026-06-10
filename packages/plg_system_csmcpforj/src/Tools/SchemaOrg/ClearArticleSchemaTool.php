<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Removes the #__schemaorg row for a content item — equivalent to setting
 * schemaType=None in the admin form, which Joomla's plugin handles by deleting
 * the row outright.
 */
final class ClearArticleSchemaTool extends AbstractTool
{
	public function getName(): string { return 'clear_article_schema'; }

	public function getDescription(): string
	{
		return 'Remove the Schema.org row for a content item (sets schemaType=None internally, '
			. 'which Joomla handles by deleting the row). '
			. 'REQUIRED ARG: item_id (the article id; article_id is also accepted as a convenience alias).';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'properties' => [
				'item_id'    => ['type' => 'integer', 'description' => 'Article id (or other entity id matching context). Pass this OR article_id.'],
				'article_id' => ['type' => 'integer', 'description' => 'Alias for item_id; use whichever name feels natural.'],
				'context'    => ['type' => 'string', 'description' => 'Default "com_content.article".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$itemId  = $this->requireItemOrArticleId($arguments);
		$context = (string) ($arguments['context'] ?? 'com_content.article');

		$delete = $this->db->getQuery(true)
			->delete($this->db->quoteName('#__schemaorg'))
			->where($this->db->quoteName('itemId') . ' = ' . $itemId)
			->where($this->db->quoteName('context') . ' = ' . $this->db->quote($context));
		$this->db->setQuery($delete)->execute();
		$affected = (int) $this->db->getAffectedRows();

		return ToolResult::json([
			'ok'      => true,
			'item_id' => $itemId,
			'context' => $context,
			'removed' => $affected > 0,
			'rows_affected' => $affected,
		]);
	}
}
