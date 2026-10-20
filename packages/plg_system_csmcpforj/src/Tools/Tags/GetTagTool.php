<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Tags;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\CategoryTagFieldsTrait;
use Joomla\CMS\User\User;

final class GetTagTool extends AbstractTool
{
	use CategoryTagFieldsTrait;

	public function getName(): string { return 'get_tag'; }

	public function getDescription(): string
	{
		return 'Fetch a single tag by id, returning its full record: title, alias, parent, level, '
			. 'path, note, description, status, access, language, metadesc, metakey, created/modified '
			. 'user and time (UTC), created_by_alias, hits, version, and params (layout, link class), '
			. 'images (intro and full image, float, alt, caption) and metadata (author, robots) as '
			. 'objects in the shape update_tag takes.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');
		$query = $this->db->getQuery(true)
			->select('*')
			->from($this->db->quoteName('#__tags'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Tag ' . $id . ' not found.');
		}

		// JSON text in the table; returned as objects, the shape update_tag
		// takes. `urls` is decoded too for completeness (no form field edits it).
		return ToolResult::json($this->ctDecodeJsonColumns($row, ['params', 'metadata', 'images', 'urls']));
	}
}
