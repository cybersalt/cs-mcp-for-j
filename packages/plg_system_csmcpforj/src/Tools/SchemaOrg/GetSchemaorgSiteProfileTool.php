<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Read the plg_system_schemaorg plugin's site-wide profile params. The plugin
 * renders an Organization or Person node into every page's <head> based on
 * just four keys (verified against Joomla 6's plugins/system/schemaorg/src/
 * Extension/Schemaorg.php@onBeforeCompileHead):
 *
 *   - baseType:    'organization' | 'person'  (lowercase — capitals break it)
 *   - name:        string (defaults to sitename when empty)
 *   - image:       string (becomes Organization.logo + .image)
 *   - socialmedia: array of {url: string}  (rendered as sameAs[])
 *   - user:        integer  (only when baseType=person — overrides name from
 *                            the Joomla user record)
 *
 * The plugin does NOT support telephone / address / geo / email. For full
 * LocalBusiness coverage use 4SEO's Business Profile instead.
 */
final class GetSchemaorgSiteProfileTool extends AbstractTool
{
	public function getName(): string { return 'get_schemaorg_site_profile'; }

	public function getDescription(): string
	{
		return 'Read the site-wide Organization/Person profile that plg_system_schemaorg emits '
			. 'on every page. Returns base_type, name (resolved against sitename if empty), image, '
			. 'social_media URLs, and user (if person). Use set_schemaorg_site_profile to change. '
			. 'NOTE: this plugin\'s site-wide schema is limited to name + image + sameAs links '
			. '— it does NOT carry telephone, address, or geo. For LocalBusiness data, configure '
			. '4SEO\'s Business Profile instead.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'enabled', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote('schemaorg'));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('plg_system_schemaorg is not installed on this site.');
		}

		$params = $row['params'] ? json_decode((string) $row['params'], true) : [];
		$params = is_array($params) ? $params : [];

		$baseType = (string) ($params['baseType'] ?? '');
		$name     = (string) ($params['name'] ?? '');
		$image    = (string) ($params['image'] ?? '');
		$user     = (int) ($params['user'] ?? 0);

		// socialmedia is stored as array of { url: "..." } objects.
		$socialMedia = [];
		foreach ((array) ($params['socialmedia'] ?? []) as $entry) {
			$entry = is_object($entry) ? get_object_vars($entry) : (array) $entry;
			$url = trim((string) ($entry['url'] ?? ''));
			if ($url !== '') { $socialMedia[] = $url; }
		}

		// Resolve effective name like the plugin does.
		$sitename = (string) Factory::getApplication()->get('sitename');
		$effectiveName = $name !== '' ? $name : $sitename;

		return ToolResult::json([
			'ok'             => true,
			'extension_id'   => (int) $row['extension_id'],
			'enabled'        => ((int) $row['enabled']) === 1,
			'configured'     => $baseType !== '',
			'base_type'      => $baseType,
			'name'           => $name,
			'effective_name' => $effectiveName,
			'image'          => $image,
			'user'           => $user > 0 ? $user : null,
			'social_media'   => $socialMedia,
			'note'           => 'For LocalBusiness fields (telephone, address, geo, email) configure 4SEO\'s Business Profile — this plugin does not support them.',
		]);
	}
}
