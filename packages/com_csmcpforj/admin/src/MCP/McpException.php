<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\MCP;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * JSON-RPC error. Carries the JSON-RPC error code (use the standard codes from
 * the JSON-RPC 2.0 spec; reserve -32000..-32099 for server-defined errors).
 */
final class McpException extends RuntimeException
{
	private mixed $data;

	public function __construct(int $code, string $message, mixed $data = null)
	{
		parent::__construct($message, $code);
		$this->data = $data;
	}

	public function getData(): mixed
	{
		return $this->data;
	}
}
