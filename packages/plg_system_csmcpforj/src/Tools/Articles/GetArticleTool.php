<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class GetArticleTool extends AbstractTool
{
	public function getName(): string { return 'get_article'; }

	public function getDescription(): string
	{
		return 'Fetch a single article by id, returning its core fields and resolved category title.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Article id.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select([
				$this->db->quoteName('a.id'), $this->db->quoteName('a.title'), $this->db->quoteName('a.alias'),
				$this->db->quoteName('a.catid'), $this->db->quoteName('c.title', 'category_title'),
				$this->db->quoteName('a.introtext'), $this->db->quoteName('a.fulltext'),
				$this->db->quoteName('a.state'), $this->db->quoteName('a.featured'),
				$this->db->quoteName('a.language'), $this->db->quoteName('a.access'),
				$this->db->quoteName('a.metadesc'), $this->db->quoteName('a.metakey'), $this->db->quoteName('a.images'),
				$this->db->quoteName('a.created'), $this->db->quoteName('a.created_by'),
				$this->db->quoteName('a.modified'), $this->db->quoteName('a.modified_by'),
				$this->db->quoteName('a.publish_up'), $this->db->quoteName('a.publish_down'),
				$this->db->quoteName('a.hits'),
			])
			->from($this->db->quoteName('#__content', 'a'))
			->leftJoin(
				$this->db->quoteName('#__categories', 'c')
				. ' ON ' . $this->db->quoteName('c.id') . ' = ' . $this->db->quoteName('a.catid')
			)
			->where($this->db->quoteName('a.id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Article ' . $id . ' not found.');
		}

		$row['images'] = $row['images'] ? json_decode((string) $row['images'], true) : null;
		return ToolResult::json($row);
	}
}
