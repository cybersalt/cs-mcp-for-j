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

	/**
	 * Returns the tool's domain/category for ?categories= filtering and
	 * UI grouping. Snake_case or PascalCase string, e.g. "articles",
	 * "menus", "joomla_update". AbstractTool derives this from the
	 * class namespace by default; tools can override.
	 */
	public function getCategory(): string;

	/**
	 * Returns MCP spec ToolAnnotations describing the tool's behavior:
	 *   - readOnlyHint   (bool) tool does not modify environment
	 *   - destructiveHint(bool) tool may perform destructive updates
	 *   - idempotentHint (bool) repeated calls have same effect as one
	 *   - openWorldHint  (bool) tool interacts with external entities
	 * Plus an optional 'title' string for display.
	 * See https://modelcontextprotocol.io tool annotations spec.
	 *
	 * @return array<string, bool|string>
	 */
	public function getMcpAnnotations(): array;

	public function execute(array $arguments, User $actor): ToolResult;
}
