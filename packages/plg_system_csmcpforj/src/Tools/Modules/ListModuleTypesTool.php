<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Modules;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * List every module type installed on this Joomla site, scoped to site (0) or
 * administrator (1) client. Discovery tool for create_module — Joomla doesn't
 * have a fixed module type list; each installed module extension adds its own
 * (mod_login, mod_menu, mod_custom, mod_articles_latest, mod_banners, plus
 * any third-party module). The AI needs the actual installed list before
 * calling create_module because the module's `module` column must match an
 * installed type or Joomla refuses the insert.
 */
final class ListModuleTypesTool extends AbstractTool
{
	public function getName(): string { return 'list_module_types'; }

	public function getDescription(): string
	{
		return 'List every module type installed on this Joomla site (mod_login, mod_menu, '
			. 'mod_custom, mod_articles_latest, etc.). Each entry shows the technical name '
			. '(use as the `module` field when calling create_module), the human title, '
			. 'description, and the client (site or admin) it ships for. Call this before '
			. 'create_module so you pass a type the site actually has installed.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'client_id' => [
					'type'        => 'integer',
					'enum'        => [0, 1],
					'description' => 'Optional. 0 = site modules (frontend), 1 = administrator modules (backend). Omit for both.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clientFilter = isset($arguments['client_id']) ? (int) $arguments['client_id'] : null;
		if ($clientFilter !== null && $clientFilter !== 0 && $clientFilter !== 1) {
			return ToolResult::error('client_id must be 0 (site) or 1 (admin) if supplied.');
		}

		$db = Factory::getContainer()->get(DatabaseInterface::class);
		$lang = Factory::getApplication()->getLanguage();

		$query = $db->getQuery(true)
			->select($db->quoteName(['element', 'client_id', 'enabled', 'manifest_cache']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('module'))
			->where($db->quoteName('state') . ' = 0')
			->order($db->quoteName('element') . ' ASC');

		if ($clientFilter !== null) {
			$query->where($db->quoteName('client_id') . ' = ' . $clientFilter);
		}

		$rows = $db->setQuery($query)->loadAssocList() ?: [];

		$types = [];
		foreach ($rows as $row) {
			$clientId    = (int) $row['client_id'];
			$clientLabel = $clientId === 1 ? 'administrator' : 'site';

			// Load the module's own language so getText resolves the title/desc.
			$basePath = ($clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE)
				. '/modules/' . $row['element'];

			$lang->load($row['element'] . '.sys', $basePath, null, false, true);
			$lang->load($row['element'], $basePath, null, false, true);

			$mc          = json_decode((string) $row['manifest_cache'], true) ?: [];
			$titleKey    = (string) ($mc['name'] ?? $row['element']);
			$descKey     = (string) ($mc['description'] ?? '');
			$version     = (string) ($mc['version'] ?? '');

			$types[] = [
				'element'     => $row['element'],
				'client'      => $clientLabel,
				'client_id'   => $clientId,
				'enabled'     => (int) $row['enabled'] === 1,
				'title'       => Text::_($titleKey),
				'description' => $descKey !== '' ? Text::_($descKey) : '',
				'version'     => $version,
			];
		}

		return ToolResult::json([
			'total'   => count($types),
			'modules' => $types,
		]);
	}
}
