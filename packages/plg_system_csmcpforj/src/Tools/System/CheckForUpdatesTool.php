<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\System;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Reads pending updates from #__updates. Does not trigger a fresh fetch — for
 * that, the user should run "Check for Updates" in Joomla admin or use the
 * scheduled task. Surfacing the cached state is enough for most agent use
 * cases ("what's out of date?").
 */
final class CheckForUpdatesTool extends AbstractTool
{
	public function getName(): string { return 'check_for_updates'; }

	public function getDescription(): string
	{
		return 'List extensions with pending updates from the locally cached update sites. '
			. 'Does NOT trigger a fresh fetch from upstream update servers — that requires '
			. 'a scheduled task or clicking Check for Updates in Joomla admin.';
	}

	public function getInputSchema(): array
	{
		return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'name', 'element', 'type', 'folder', 'client_id', 'version', 'description', 'infourl']))
			->from($this->db->quoteName('#__updates'))
			->order($this->db->quoteName('name'));
		$rows = $this->db->setQuery($query)->loadAssocList() ?: [];
		return ToolResult::json(['count' => count($rows), 'updates' => $rows]);
	}
}
