<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * High-level summary of the 4SEO install: is com_forseo present? Enabled?
 * What manifest version? What other for(seo|sef|analytics|ai) Weeblr
 * extensions are alongside it? Lets the agent answer "is 4SEO available
 * here?" without having to know about #__forseo_*.
 */
final class Get4seoComponentInfoTool extends AbstractTool
{
	public function getName(): string { return 'get_4seo_component_info'; }

	public function getDescription(): string
	{
		return 'Check whether 4SEO is installed and enabled, and surface basic info '
			. '(manifest version, Weeblr sibling extensions). Run this first to confirm 4SEO '
			. 'is actually present on the site before calling the schema/CRUD tools.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'name', 'element', 'type', 'folder', 'enabled', 'manifest_cache']))
			->from($this->db->quoteName('#__extensions'))
			->where('(' . $this->db->quoteName('element') . ' LIKE ' . $this->db->quote('com_for%')
				. ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $this->db->quote('forseo%')
				. ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $this->db->quote('forsef%')
				. ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $this->db->quote('forai%')
				. ' OR ' . $this->db->quoteName('element') . ' LIKE ' . $this->db->quote('foranalytics%')
				. ')')
			->order($this->db->quoteName('type') . ', ' . $this->db->quoteName('element'));
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];

		$forseo = null;
		foreach ($rows as $row) {
			if (($row['type'] ?? '') === 'component' && ($row['element'] ?? '') === 'com_forseo') {
				$mc = $row['manifest_cache'] ? json_decode((string) $row['manifest_cache'], true) : null;
				$forseo = [
					'extension_id' => (int) $row['extension_id'],
					'name'         => $row['name'],
					'enabled'      => (int) $row['enabled'] === 1,
					'version'      => $mc['version'] ?? null,
					'creationDate' => $mc['creationDate'] ?? null,
					'author'       => $mc['author'] ?? null,
				];
			}
			unset($row['manifest_cache']);
		}

		return ToolResult::json([
			'forseo_installed'  => $forseo !== null,
			'forseo'            => $forseo,
			'related_extensions' => $rows,
		]);
	}
}
