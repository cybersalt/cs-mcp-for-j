<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Languages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListContentLanguagesTool extends AbstractTool
{
	public function getName(): string { return 'list_content_languages'; }

	public function getDescription(): string
	{
		return 'List content languages (the records in #__languages used to tag articles, '
			. 'menu items, modules with a language). Returns lang_id, lang_code, title, sef, '
			. 'access, published.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['lang_id', 'lang_code', 'title', 'title_native', 'sef', 'image', 'access', 'published', 'ordering']))
			->from($this->db->quoteName('#__languages'))
			->order($this->db->quoteName('ordering'));

		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'content_languages' => $rows]);
	}
}
