<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Overwrite (or create) a file under a template's media root with provided
 * contents. Write-gated by the standard csmcpforj.write ACL, additionally
 * gated by an extension allowlist that excludes PHP (see
 * TemplateFilePathTrait::assertWritableExtension).
 *
 * No backup is written before overwriting — the caller is expected to have
 * just called read_template_file to retrieve the prior contents if they
 * care to preserve them. (Joomla's own Template Files editor doesn't snapshot
 * either; this matches that behaviour.)
 *
 * Returns the post-write file size + sha256 so the AI can confirm what
 * actually landed on disk matches what it intended to write.
 */
final class WriteTemplateFileTool extends AbstractTool
{
	use TemplateFilePathTrait;

	public function getName(): string { return 'write_template_file'; }

	public function getDescription(): string
	{
		return 'Save contents to a file under a Joomla template\'s media root '
			. '(media/templates/<client>/<template>/<path>). Overwrites if the file '
			. 'exists, creates if it doesn\'t. Equivalent to the Save button in the '
			. 'Joomla admin Template Files editor. Allowed extensions: CSS, JS, SCSS, '
			. 'JSON, SVG, text, images, fonts. PHP is intentionally NOT allowed in '
			. 'this version — use the Joomla admin Template Files editor for layout '
			. 'overrides until the per-permission .php gate ships in a future release. '
			. 'No backup snapshot is taken before overwrite — call read_template_file '
			. 'first if you need to preserve the prior contents.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['template', 'path', 'content'],
			'properties' => [
				'client'           => ['type' => 'string', 'enum' => ['site', 'administrator'], 'description' => 'Optional. "site" (default) or "administrator".'],
				'template'         => ['type' => 'string', 'description' => 'Template short element name, e.g. "cassiopeia".'],
				'path'             => ['type' => 'string', 'description' => 'Path relative to the template root, e.g. "css/user.css". Parent directory must exist.'],
				'content'          => ['type' => 'string', 'description' => 'File contents. For binary files, base64-encode and set content_encoding=base64.'],
				'content_encoding' => ['type' => 'string', 'enum' => ['utf-8', 'base64'], 'description' => 'Default utf-8. Set to base64 for binary files (images, fonts).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clientId = $this->parseClientId($arguments);
		$template = $this->requireString($arguments, 'template');
		$path     = $this->requireString($arguments, 'path');
		$content  = (string) ($arguments['content'] ?? '');
		$encoding = strtolower((string) ($arguments['content_encoding'] ?? 'utf-8'));

		$absolute = $this->resolveTemplatePath($clientId, $template, $path, false, true);
		$this->assertWritableExtension($absolute);

		// Decode binary payloads. Reject malformed base64 explicitly instead of
		// silently writing garbage to disk.
		if ($encoding === 'base64') {
			$decoded = base64_decode($content, true);
			if ($decoded === false) {
				return ToolResult::error('content_encoding=base64 but content is not valid base64.');
			}
			$bytes = $decoded;
		} elseif ($encoding === 'utf-8' || $encoding === '') {
			$bytes = $content;
		} else {
			return ToolResult::error('content_encoding must be utf-8 or base64.');
		}

		$existed = is_file($absolute);
		$priorSize = $existed ? (int) filesize($absolute) : 0;

		if (@file_put_contents($absolute, $bytes) === false) {
			return ToolResult::error('Failed to write file: ' . $path . ' (check filesystem permissions on the template directory).');
		}

		return ToolResult::json([
			'ok'         => true,
			'client'     => $clientId === 1 ? 'administrator' : 'site',
			'template'   => $template,
			'path'       => $path,
			'created'    => !$existed,
			'prior_size' => $priorSize,
			'size'       => strlen($bytes),
			'sha256'     => hash('sha256', $bytes),
		]);
	}
}
