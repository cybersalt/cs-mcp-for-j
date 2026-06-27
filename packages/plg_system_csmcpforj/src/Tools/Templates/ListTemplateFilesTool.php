<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Recursively list files under a template's media root. Read-only — counterpart
 * to read_template_file / write_template_file.
 *
 * Returns paths relative to the template root so the AI can feed any one of
 * them back into read_template_file or write_template_file without having to
 * construct the jail prefix itself.
 */
final class ListTemplateFilesTool extends AbstractTool
{
	use TemplateFilePathTrait;

	public function getName(): string { return 'list_template_files'; }

	public function getDescription(): string
	{
		return 'Recursively list every file under a Joomla template\'s media root '
			. '(media/templates/<client>/<template>/). Returns an array of {path, size, mtime} '
			. 'with paths relative to the template root, suitable to feed back to '
			. 'read_template_file or write_template_file. Use this to discover what files exist '
			. 'before reading or writing — e.g. find css/user.css before adding custom CSS.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['template'],
			'properties' => [
				'client'   => ['type' => 'string', 'enum' => ['site', 'administrator'], 'description' => 'Optional. "site" (default) or "administrator".'],
				'template' => ['type' => 'string', 'description' => 'Template short element name, e.g. "cassiopeia", "atum", "cassiopeia-child".'],
				'subpath'  => ['type' => 'string', 'description' => 'Optional. Subdirectory within the template root to scope the listing (e.g. "css" or "html/com_content"). Default = root.'],
				'limit'    => ['type' => 'integer', 'description' => 'Optional. Cap on returned entries (default 500).'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$clientId = $this->parseClientId($arguments);
		$template = $this->requireString($arguments, 'template');
		$subpath  = trim((string) ($arguments['subpath'] ?? ''));
		$limit    = max(1, min(5000, (int) ($arguments['limit'] ?? 500)));

		$rootAbsolute = $this->resolveTemplatePath($clientId, $template, $subpath, true, false);
		if (!is_dir($rootAbsolute)) {
			return ToolResult::error('Listing target is not a directory: ' . $subpath);
		}

		// Anchor for "relative to template root" path calculation.
		$jailRoot = realpath(JPATH_ROOT . '/media/templates/' . ($clientId === 1 ? 'administrator' : 'site') . '/' . $template);
		$jailLen  = strlen((string) $jailRoot) + 1;

		$files = [];
		$iter  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($rootAbsolute, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iter as $info) {
			if (!$info->isFile()) {
				continue;
			}
			$abs       = $info->getPathname();
			$relative  = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($abs, $jailLen)), '/');
			$files[]   = [
				'path'  => $relative,
				'size'  => $info->getSize(),
				'mtime' => date('Y-m-d H:i:s', $info->getMTime()),
			];
			if (count($files) >= $limit) {
				break;
			}
		}

		// Stable sort by path for predictable output (AI can re-call and get same order).
		usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

		return ToolResult::json([
			'client'     => $clientId === 1 ? 'administrator' : 'site',
			'template'   => $template,
			'subpath'    => $subpath,
			'root'       => 'media/templates/' . ($clientId === 1 ? 'administrator' : 'site') . '/' . $template . ($subpath !== '' ? '/' . $subpath : ''),
			'count'      => count($files),
			'limit'      => $limit,
			'files'      => $files,
		]);
	}
}
