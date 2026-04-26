<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListModulesTool extends AbstractTool
{
	public function getName(): string { return 'list_modules'; }

	public function getDescription(): string
	{
		return 'List published/unpublished modules. Filter by client_id (0=site, 1=admin), '
			. 'position, module type, language, or published state.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'client_id' => ['type' => 'integer', 'enum' => [0, 1]],
				'position'  => ['type' => 'string'],
				'module'    => ['type' => 'string', 'description' => 'Module element, e.g. "mod_menu".'],
				'language'  => ['type' => 'string'],
				'published' => ['type' => 'integer', 'enum' => [0, 1]],
				'limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'title', 'note', 'position', 'module', 'access', 'showtitle', 'published', 'language', 'client_id', 'ordering']))
			->from($this->db->quoteName('#__modules'))
			->order($this->db->quoteName('client_id') . ' ASC, ' . $this->db->quoteName('position') . ' ASC, ' . $this->db->quoteName('ordering') . ' ASC');

		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}
		if (!empty($arguments['position'])) {
			$query->where($this->db->quoteName('position') . ' = ' . $this->db->quote((string) $arguments['position']));
		}
		if (!empty($arguments['module'])) {
			$query->where($this->db->quoteName('module') . ' = ' . $this->db->quote((string) $arguments['module']));
		}
		if (!empty($arguments['language'])) {
			$query->where($this->db->quoteName('language') . ' = ' . $this->db->quote((string) $arguments['language']));
		}
		if (isset($arguments['published'])) {
			$query->where($this->db->quoteName('published') . ' = ' . (int) $arguments['published']);
		}

		$limit = isset($arguments['limit']) ? max(1, min(500, (int) $arguments['limit'])) : 100;
		$query->setLimit($limit);

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'modules' => $rows]);
	}
}
