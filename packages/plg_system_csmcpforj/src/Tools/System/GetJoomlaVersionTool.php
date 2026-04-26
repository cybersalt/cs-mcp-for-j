<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\CMS\Version;

final class GetJoomlaVersionTool extends AbstractTool
{
	public function getName(): string { return 'get_joomla_version'; }

	public function getDescription(): string
	{
		return 'Returns the running Joomla version, codename, release/dev statuses, and the '
			. 'PHP version this Joomla instance is running on.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$v = new Version();
		return ToolResult::json([
			'short_version' => $v->getShortVersion(),
			'long_version'  => $v->getLongVersion(),
			'is_in_dev'     => $v->isInDevelopmentState(),
			'codename'      => Version::CODENAME,
			'php_version'   => PHP_VERSION,
			'mcp_extension' => 'cs-mcp-for-j 1.0.0',
		]);
	}
}
