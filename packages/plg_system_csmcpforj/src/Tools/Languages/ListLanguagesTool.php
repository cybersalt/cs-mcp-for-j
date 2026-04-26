<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Languages;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListLanguagesTool extends AbstractTool
{
	public function getName(): string { return 'list_languages'; }

	public function getDescription(): string
	{
		return 'List installed languages (the language packs in #__extensions). For the '
			. 'multilingual content language records (used to tag articles by language), use list_content_languages.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'element', 'name', 'enabled', 'client_id']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('language'))
			->order($this->db->quoteName('client_id') . ', ' . $this->db->quoteName('element'));

		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'languages' => $rows]);
	}
}
