<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Convenience base for ToolInterface implementations.
 *
 * Wraps each call in a try/catch that returns a uniform ToolResult::error()
 * so individual tools don't have to repeat boilerplate. Provides helpers for
 * booting other components and grabbing their MVCFactory / Administrator
 * models — the safest way to write data into core Joomla tables.
 */
abstract class AbstractTool implements ToolInterface
{
	public function __construct(protected readonly DatabaseInterface $db) {}

	final public function execute(array $arguments, User $actor): ToolResult
	{
		try {
			return $this->run($arguments, $actor);
		} catch (Throwable $e) {
			return ToolResult::error($this->getName() . ' failed: ' . $e->getMessage());
		}
	}

	abstract protected function run(array $arguments, User $actor): ToolResult;

	protected function app(): CMSApplication
	{
		return Factory::getApplication();
	}

	protected function bootComponent(string $component): ComponentInterface
	{
		return $this->app()->bootComponent($component);
	}

	/**
	 * Returns an Administrator model from another component, with the request-
	 * coupling disabled so it doesn't pull state from the live request.
	 */
	protected function getModel(string $component, string $name, string $client = 'Administrator'): object
	{
		return $this->bootComponent($component)->getMVCFactory()
			->createModel($name, $client, ['ignore_request' => true]);
	}

	protected function requireString(array $arguments, string $key): string
	{
		$value = trim((string) ($arguments[$key] ?? ''));
		if ($value === '') {
			throw new \InvalidArgumentException($key . ' is required.');
		}
		return $value;
	}

	protected function requirePositiveInt(array $arguments, string $key): int
	{
		$value = (int) ($arguments[$key] ?? 0);
		if ($value <= 0) {
			throw new \InvalidArgumentException($key . ' is required and must be a positive integer.');
		}
		return $value;
	}
}
