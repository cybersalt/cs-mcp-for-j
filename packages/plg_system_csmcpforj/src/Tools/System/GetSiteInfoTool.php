<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

/**
 * Surface a curated subset of site configuration. Deliberately omits secrets:
 * no DB password, no SMTP password, no secret/sef tokens, no captcha keys.
 */
final class GetSiteInfoTool extends AbstractTool
{
	public function getName(): string { return 'get_site_info'; }

	public function getDescription(): string
	{
		return 'Returns site name, base URL, locale, timezone, mailer-from settings, debug/SEF '
			. 'flags, default editor, and basic counts (articles, users, modules, plugins). '
			. 'Sensitive credentials are NEVER returned.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$config = Factory::getApplication()->getConfig();

		$counts = [
			'articles' => (int) $this->scalar('SELECT COUNT(*) FROM #__content'),
			'users'    => (int) $this->scalar('SELECT COUNT(*) FROM #__users'),
			'modules'  => (int) $this->scalar('SELECT COUNT(*) FROM #__modules'),
			'plugins_enabled' => (int) $this->scalar(
				"SELECT COUNT(*) FROM #__extensions WHERE type = 'plugin' AND enabled = 1"
			),
		];

		return ToolResult::json([
			'site_name'    => (string) $config->get('sitename', ''),
			'base_url'     => rtrim(Uri::root(), '/'),
			'locale'       => (string) $config->get('language', ''),
			'timezone'     => (string) $config->get('offset', 'UTC'),
			'mailfrom'     => (string) $config->get('mailfrom', ''),
			'fromname'     => (string) $config->get('fromname', ''),
			'mailer'       => (string) $config->get('mailer', ''),
			'sef'          => (bool) $config->get('sef', false),
			'sef_rewrite'  => (bool) $config->get('sef_rewrite', false),
			'debug'        => (bool) $config->get('debug', false),
			'editor'       => (string) $config->get('editor', ''),
			'cache_enabled' => (bool) $config->get('caching', 0),
			'counts'       => $counts,
		]);
	}

	private function scalar(string $sql): mixed
	{
		return $this->db->setQuery($sql)->loadResult();
	}
}
