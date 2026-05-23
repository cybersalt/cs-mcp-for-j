<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\BusinessProfile;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Delete the 4SEO Business Profile config row entirely.
 *
 * After this call, 4SEO falls back to the empty-skeleton render — the same
 * state as a brand-new install where the human has never visited the
 * Business Profile form. The site still emits a #defaultBusiness node, just
 * without telephone/address/geo/logo.
 *
 * Use this when you want to wipe the profile rather than reset individual
 * fields. For a "reset to defaults but keep the row" alternative, call
 * set_4seo_business_profile with mode=replace and no business data.
 */
final class ClearBusinessProfileTool extends AbstractTool
{
	public function getName(): string { return 'clear_4seo_business_profile'; }

	public function getDescription(): string
	{
		return 'Delete 4SEO\'s site-wide Business Profile config row (#__forseo_config scope=default, '
			. 'key=sd) entirely. After this the LocalBusiness JSON-LD on every page reverts to the '
			. 'empty skeleton (no telephone, dangling address/geo @id refs). Use '
			. 'set_4seo_business_profile to rebuild. Idempotent — returns ok:true even if the row '
			. 'didn\'t exist.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$fullTable = $this->db->getPrefix() . 'forseo_config';

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('scope') . ' = ' . $this->db->quote('default'))
			->where($this->db->quoteName('key') . ' = ' . $this->db->quote('sd'));
		$id = (int) $this->db->setQuery($query)->loadResult();

		if ($id <= 0) {
			return ToolResult::json([
				'ok'      => true,
				'action'  => 'noop',
				'note'    => 'No (scope=default, key=sd) row existed; nothing to delete.',
			]);
		}

		$delete = $this->db->getQuery(true)
			->delete($this->db->quoteName($fullTable))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$this->db->setQuery($delete)->execute();

		return ToolResult::json([
			'ok'     => true,
			'action' => 'deleted',
			'row_id' => $id,
		]);
	}
}
