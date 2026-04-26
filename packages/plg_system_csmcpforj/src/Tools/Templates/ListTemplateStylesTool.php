<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

final class ListTemplateStylesTool extends AbstractTool
{
	public function getName(): string { return 'list_template_styles'; }

	public function getDescription(): string
	{
		return 'List template styles. Each row is a style: id, template element, client_id, '
			. 'title, home flag (1 = default for that client+language).';
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
			->select($this->db->quoteName(['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent']))
			->from($this->db->quoteName('#__template_styles'))
			->order($this->db->quoteName('client_id') . ' ASC, ' . $this->db->quoteName('template') . ' ASC');

		if (isset($arguments['client_id'])) {
			$query->where($this->db->quoteName('client_id') . ' = ' . (int) $arguments['client_id']);
		}

		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'styles' => $rows]);
	}
}
