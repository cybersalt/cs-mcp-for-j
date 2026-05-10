<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads com_forseo's component params (the Options screen) directly from
 * #__extensions. Site-wide 4SEO settings (default templates, global schema,
 * crawler defaults) live here. This is read-direct rather than through
 * ComponentHelper::getParams() to avoid stale cache when paired with
 * Set4seoComponentParamsTool in the same MCP session.
 */
final class Get4seoComponentParamsTool extends AbstractTool
{
	public function getName(): string { return 'get_4seo_component_params'; }

	public function getDescription(): string
	{
		return 'Read the component params (Options screen settings) for com_forseo. Returns '
			. 'the full params JSON as an object — site-wide 4SEO settings (default templates, '
			. 'global schema overrides, crawler defaults) live here.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'enabled', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_forseo'));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('com_forseo is not installed on this site.');
		}

		$params = $row['params'] ? json_decode((string) $row['params'], true) : [];
		return ToolResult::json([
			'extension_id' => (int) $row['extension_id'],
			'enabled'      => (int) $row['enabled'] === 1,
			'params'       => is_array($params) ? $params : [],
		]);
	}
}
