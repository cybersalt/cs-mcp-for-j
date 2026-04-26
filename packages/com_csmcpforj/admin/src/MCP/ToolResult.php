<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

final class ToolResult
{
	/**
	 * @param array<int, array<string, mixed>> $content MCP content blocks
	 */
	public function __construct(
		public readonly array $content,
		public readonly bool $isError = false
	) {}

	public static function text(string $text, bool $isError = false): self
	{
		return new self(
			[['type' => 'text', 'text' => $text]],
			$isError
		);
	}

	public static function json(mixed $data, bool $isError = false): self
	{
		return self::text(
			json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			$isError
		);
	}

	public static function error(string $message): self
	{
		return self::text($message, true);
	}

	public function toArray(): array
	{
		return [
			'content' => $this->content,
			'isError' => $this->isError,
		];
	}
}
