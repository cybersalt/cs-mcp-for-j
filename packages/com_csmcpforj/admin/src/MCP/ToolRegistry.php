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
	 * @return array<int, array{name: string, description: string, inputSchema: array}>
	 */
	public function describeForMcp(): array
	{
		$out = [];
		foreach ($this->tools as $tool) {
			$out[] = [
				'name'        => $tool->getName(),
				'description' => $tool->getDescription(),
				'inputSchema' => $tool->getInputSchema(),
			];
		}

		return $out;
	}
}
