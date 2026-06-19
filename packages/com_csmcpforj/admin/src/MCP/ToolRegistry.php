<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

final class ToolRegistry
{
	/** @var array<string, ToolInterface> */
	private array $tools = [];

	public function register(ToolInterface $tool): void
	{
		$this->tools[$tool->getName()] = $tool;
	}

	public function has(string $name): bool
	{
		return isset($this->tools[$name]);
	}

	public function get(string $name): ?ToolInterface
	{
		return $this->tools[$name] ?? null;
	}

	/** @return array<string, ToolInterface> */
	public function all(): array
	{
		return $this->tools;
	}

	/**
	 * Returns tools formatted for the MCP tools/list response.
	 *
	 * Honors two optional server-side filters:
	 *   - $categories: if non-empty, only tools whose getCategory() matches one
	 *     of the given (case-insensitive) values are emitted. Lets MCP clients
	 *     pass `?categories=articles,users` on the endpoint URL to shrink the
	 *     tools/list payload — important for small-context models that choke
	 *     on the full ~95-tool list.
	 *   - $readOnly: if true, only tools whose annotations declare
	 *     readOnlyHint=true are emitted. Backed by the component's
	 *     read_only_mode config switch — lets an operator hand an MCP client a
	 *     "browse but don't mutate" surface for safer exploration.
	 *
	 * Both filters are independent and can apply together (read-only browsing
	 * limited to a category subset).
	 *
	 * Each emitted descriptor includes MCP spec ToolAnnotations under the
	 * `annotations` key so MCP-spec-aware clients can render safety badges
	 * (read-only / destructive / idempotent) on each tool.
	 *
	 * @param array<int, string> $categories Lowercase domain slugs to keep, or [] for all
	 * @return array<int, array{name: string, description: string, inputSchema: array, annotations?: array}>
	 */
	public function describeForMcp(array $categories = [], bool $readOnly = false): array
	{
		$categories = array_values(array_filter(array_map(
			static fn($c): string => strtolower(trim((string) $c)),
			$categories
		)));

		$out = [];
		foreach ($this->tools as $tool) {
			if ($categories !== [] && !in_array(strtolower($tool->getCategory()), $categories, true)) {
				continue;
			}

			$annotations = $tool->getMcpAnnotations();

			if ($readOnly && empty($annotations['readOnlyHint'])) {
				continue;
			}

			$out[] = [
				'name'        => $tool->getName(),
				'description' => $tool->getDescription(),
				'inputSchema' => $tool->getInputSchema(),
				'annotations' => $annotations,
			];
		}

		return $out;
	}

	/**
	 * Returns true if the named tool passes the given filter set — i.e. could
	 * have appeared in `describeForMcp($categories, $readOnly)`. Used by the
	 * Server's tools/call dispatcher so a client can't invoke a tool that the
	 * matching tools/list call wouldn't have shown them.
	 */
	public function isCallable(string $name, array $categories = [], bool $readOnly = false): bool
	{
		$tool = $this->get($name);
		if ($tool === null) {
			return false;
		}

		$categories = array_values(array_filter(array_map(
			static fn($c): string => strtolower(trim((string) $c)),
			$categories
		)));

		if ($categories !== [] && !in_array(strtolower($tool->getCategory()), $categories, true)) {
			return false;
		}

		if ($readOnly && empty($tool->getMcpAnnotations()['readOnlyHint'])) {
			return false;
		}

		return true;
	}
}
