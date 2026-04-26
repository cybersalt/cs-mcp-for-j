<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

final class ClearCacheTool extends AbstractTool
{
	public function getName(): string { return 'clear_cache'; }

	public function getDescription(): string
	{
		return 'Clear Joomla cache. Optional: group (e.g. "com_content", "_system") and/or '
			. 'client_id (0=site, 1=admin). Defaults to clearing every group on both clients.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'group'     => ['type' => 'string', 'description' => 'Single cache group to clear. Omit for all groups.'],
				'client_id' => ['type' => 'integer', 'enum' => [0, 1], 'description' => 'Default: both clients.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$specifiedGroup    = $arguments['group'] ?? null;
		$specifiedClientId = $arguments['client_id'] ?? null;

		$cleared = [];
		$cleanFn = function (string $group, int $clientId) use (&$cleared) {
			$cache = Factory::getCache($group, '');
			$cache->setCaching(true);
			$cache->clean();
			$cleared[] = "$group@$clientId";
		};

		if ($specifiedGroup) {
			$clients = $specifiedClientId !== null ? [(int) $specifiedClientId] : [0, 1];
			foreach ($clients as $cid) {
				$cleanFn((string) $specifiedGroup, $cid);
			}
		} else {
			// Walk every cache group in the site/admin caches
			$paths = [];
			if ($specifiedClientId === null || (int) $specifiedClientId === 0) {
				$paths[0] = JPATH_SITE . '/cache';
			}
			if ($specifiedClientId === null || (int) $specifiedClientId === 1) {
				$paths[1] = JPATH_ADMINISTRATOR . '/cache';
			}
			foreach ($paths as $cid => $cachePath) {
				if (!is_dir($cachePath)) { continue; }
				foreach ((array) glob($cachePath . '/*', GLOB_ONLYDIR) as $dir) {
					$cleanFn(basename($dir), $cid);
				}
			}
		}

		return ToolResult::json(['ok' => true, 'cleared' => $cleared]);
	}
}
