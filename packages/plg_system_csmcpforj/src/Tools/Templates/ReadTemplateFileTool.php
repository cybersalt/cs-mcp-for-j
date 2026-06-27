<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Return the text contents of a file under a template's media root. Read-only;
 * read access is broader than write access (the write allowlist excludes PHP,
 * but read access intentionally includes it so the AI can inspect overrides
 * before deciding whether a CSS-only customisation would suffice).
 *
 * Returns content + the size + mtime so the AI can detect concurrent
 * modification across calls (read → consider → write workflow).
 */
final class ReadTemplateFileTool extends AbstractTool
{
	use TemplateFilePathTrait;

	public function getName(): string { return 'read_template_file'; }

	public function getDescription(): string
	{
		return 'Return the text contents of a file under a Joomla template\'s media '
			. 'root (media/templates/<client>/<template>/<path>). Read-only. '
			. 'Counterpart to list_template_files (use that first to discover paths) '
			. 'and write_template_file (use that to save the edited contents back). '
			. 'Reads any text file regardless of extension; binary files are returned '
			. 'as a base64-encoded string with a content_encoding=base64 flag so the '
			. 'AI can still inspect e.g. an image file size before deciding what to do.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['template', 'path'],
			'properties' => [
				'client'   => ['type' => 'string', 'enum' => ['site', 'administrator'], 'description' => 'Optional. "site" (default) or "administrator".'],
				'template' => ['type' => 'string', 'description' => 'Template short element name, e.g. "cassiopeia".'],
				'path'     => ['type' => 'string', 'description' => 'Path relative to the template root, e.g. "css/user.css".'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clientId = $this->parseClientId($arguments);
		$template = $this->requireString($arguments, 'template');
		$path     = $this->requireString($arguments, 'path');

		$absolute = $this->resolveTemplatePath($clientId, $template, $path, true, false);

		$bytes = @file_get_contents($absolute);
		if ($bytes === false) {
			return ToolResult::error('Could not read file: ' . $path);
		}

		// Detect binary content heuristically (presence of null byte). For binary
		// files, return base64 so the JSON-RPC envelope stays valid — text files
		// pass through verbatim.
		$isBinary = str_contains($bytes, "\0");

		return ToolResult::json([
			'client'           => $clientId === 1 ? 'administrator' : 'site',
			'template'         => $template,
			'path'             => $path,
			'size'             => strlen($bytes),
			'mtime'            => date('Y-m-d H:i:s', filemtime($absolute)),
			'content_encoding' => $isBinary ? 'base64' : 'utf-8',
			'content'          => $isBinary ? base64_encode($bytes) : $bytes,
		]);
	}
}
