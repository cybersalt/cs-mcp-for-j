<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Set the plg_system_schemaorg plugin's site-wide profile params. The plugin
 * is locked in #__extensions but legitimately user-editable through Joomla's
 * own admin UI — this tool bypasses the lock the same way the admin UI does.
 *
 * Critically: baseType must be lowercase. The plugin's onBeforeCompileHead
 * does `\in_array($baseType, ['organization', 'person'])` and returns early
 * if it's anything else. Writing "Organization" (capital O) silently kills
 * the plugin's whole @graph output — including per-content-item schemas set
 * via set_article_schema / set_article_custom_jsonld. This tool enforces
 * lowercase so callers can't accidentally cause that regression.
 *
 * The plugin only supports name + image + sameAs (via socialmedia) on the
 * site-wide profile — NOT telephone, address, geo, email, etc. For full
 * LocalBusiness data, use 4SEO's Business Profile instead.
 */
final class SetSchemaorgSiteProfileTool extends AbstractTool
{
	private const VALID_BASE_TYPES = ['organization', 'person'];

	public function getName(): string { return 'set_schemaorg_site_profile'; }

	public function getDescription(): string
	{
		return 'Set the site-wide Organization/Person profile for plg_system_schemaorg, which '
			. 'renders into every page\'s JSON-LD <head>. Required: base_type ("organization" or '
			. '"person" — LOWERCASE, capital "Organization" silently breaks the plugin). Optional: '
			. 'name (overrides sitename), image (URL or Joomla media path — becomes the logo), '
			. 'social_media (array of profile URLs, rendered as sameAs[]), and user (integer, '
			. 'only when base_type=person — picks a Joomla user record). mode defaults to '
			. '"merge" (untouched keys preserved); mode="replace" wipes everything not supplied. '
			. 'IMPORTANT: this plugin does NOT support telephone, address, geo, or email — for '
			. 'a full LocalBusiness profile, configure 4SEO\'s Business Profile instead. After a '
			. 'write, verify with fetch_rendered_url that the @graph still renders on a known '
			. 'page; if it doesn\'t, the base_type may have been corrupted.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'base_type'    => ['type' => 'string', 'enum' => self::VALID_BASE_TYPES, 'description' => 'organization or person — MUST be lowercase.'],
				'name'         => ['type' => 'string', 'description' => 'Display name. Leave unset/empty to use the Joomla sitename.'],
				'image'        => ['type' => 'string', 'description' => 'Logo URL or Joomla media path (e.g. "images/logo.png").'],
				'social_media' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Array of social profile URLs (each becomes one sameAs entry).',
				],
				'user'         => ['type' => 'integer', 'description' => 'Joomla user id (only when base_type=person; 0 falls back to name).'],
				'mode'         => ['type' => 'string', 'enum' => ['merge', 'replace'], 'description' => 'Default "merge": untouched params preserved. "replace" wipes everything not supplied here.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$mode = (string) ($arguments['mode'] ?? 'merge');
		if (!in_array($mode, ['merge', 'replace'], true)) {
			return ToolResult::error('mode must be merge or replace.');
		}

		// Validate base_type early — this is the case-sensitivity trap.
		if (array_key_exists('base_type', $arguments)) {
			$bt = (string) $arguments['base_type'];
			if (!in_array($bt, self::VALID_BASE_TYPES, true)) {
				return ToolResult::error(
					'base_type must be exactly "organization" or "person" (lowercase). '
					. 'Got: ' . var_export($bt, true) . '. Capital-O "Organization" silently '
					. 'disables the entire schemaorg @graph including per-article schemas.'
				);
			}
		} elseif ($mode === 'replace') {
			return ToolResult::error('base_type is required when mode=replace.');
		}

		// Build the incoming patch in plugin-native keys.
		$patch = [];
		if (array_key_exists('base_type', $arguments))    { $patch['baseType'] = (string) $arguments['base_type']; }
		if (array_key_exists('name', $arguments))         { $patch['name']     = (string) $arguments['name']; }
		if (array_key_exists('image', $arguments))        { $patch['image']    = (string) $arguments['image']; }
		if (array_key_exists('user', $arguments))         { $patch['user']     = (int) $arguments['user']; }
		if (array_key_exists('social_media', $arguments)) {
			$urls = $arguments['social_media'];
			if (!is_array($urls)) {
				return ToolResult::error('social_media must be an array of URL strings.');
			}
			$socialmedia = [];
			foreach ($urls as $url) {
				$url = trim((string) $url);
				if ($url === '') { continue; }
				if (!preg_match('#^https?://#i', $url)) {
					return ToolResult::error('social_media URLs must start with http:// or https://. Bad: ' . $url);
				}
				$socialmedia[] = ['url' => $url];
			}
			$patch['socialmedia'] = $socialmedia;
		}

		// Cross-field sanity: if base_type=organization, ignore any incoming
		// `user` (the plugin only honours `user` when baseType=person).
		if (($patch['baseType'] ?? null) === 'organization' && isset($patch['user']) && $patch['user'] > 0) {
			unset($patch['user']);
		}

		// Read the row.
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'protected', 'locked', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
			->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote('schemaorg'));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::error('plg_system_schemaorg is not installed on this site.');
		}
		if (!empty($row['protected'])) {
			return ToolResult::error('plg_system_schemaorg is flagged protected — refusing to modify.');
		}

		$existing = $row['params'] ? json_decode((string) $row['params'], true) : [];
		$existing = is_array($existing) ? $existing : [];

		$merged = $mode === 'replace' ? $patch : array_replace($existing, $patch);

		$update = $this->db->getQuery(true)
			->update($this->db->quoteName('#__extensions'))
			->set($this->db->quoteName('params') . ' = ' . $this->db->quote(json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
			->where($this->db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);
		$this->db->setQuery($update)->execute();

		return ToolResult::json([
			'ok'           => true,
			'extension_id' => (int) $row['extension_id'],
			'mode'         => $mode,
			'changed_keys' => array_keys($patch),
			'params'       => $merged,
			'verify_with'  => 'After this write, call fetch_rendered_url on any front-end page and confirm the JSON-LD @graph still contains both the site-wide Organization/Person AND any per-article schema you set previously. If the @graph is missing, the base_type may be invalid.',
		]);
	}
}
