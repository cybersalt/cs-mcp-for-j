<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

interface ToolInterface
{
	public function getName(): string;

	public function getDescription(): string;

	/**
	 * Returns the JSON Schema describing this tool's input arguments.
	 * Used directly in the tools/list MCP response.
	 */
	public function getInputSchema(): array;

	/**
	 * Returns 'use' (read-only tools) or 'write' (mutating tools).
	 * Maps to ACL actions csmcpforj.use and csmcpforj.write.
	 */
	public function getRequiredPermission(): string;

	public function execute(array $arguments, User $actor): ToolResult;
}
