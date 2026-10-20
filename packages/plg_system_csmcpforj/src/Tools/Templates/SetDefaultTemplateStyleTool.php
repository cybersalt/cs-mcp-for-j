<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Sets a template style as the default ("home") style for its client. Equivalent
 * to clicking the home star icon in Templates → Styles, i.e. StyleModel::
 * setHome() in Joomla 6.2.0, which this mirrors:
 *
 *   - Only the style that was home = '1' is reset. Until 2026-10-20 this tool
 *     reset EVERY style of the client to '0', which on a multilingual site
 *     also wiped the per-language defaults (home = 'en-GB' etc.) that the
 *     Default column's flags show.
 *   - A style whose template extension is disabled is refused, as Joomla
 *     refuses it (COM_TEMPLATES_ERROR_SAVE_DISABLED_TEMPLATE): making it the
 *     default would leave the site rendering a disabled template.
 *
 * For a per-language default use update_template_style's `home`.
 */
final class SetDefaultTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'set_default_template_style'; }

	public function getDescription(): string
	{
		return 'Make a template style the default for its client (site or admin), for all languages. '
			. 'Per-language defaults on a multilingual site are kept. Refused when the style\'s template '
			. 'is disabled. Use list_template_styles to find the id; for a per-language default use '
			. 'update_template_style with home set to a language code.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => ['id' => ['type' => 'integer']],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['client_id', 'template', 'title']))
			->from($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('id') . ' = ' . $id);
		$style = $this->db->setQuery($query)->loadAssoc();
		if (!$style) {
			return ToolResult::error('Template style ' . $id . ' not found.');
		}

		$clientId = (int) $style['client_id'];

		$templateEnabled = $this->db->setQuery(
			$this->db->getQuery(true)
				->select($this->db->quoteName('enabled'))
				->from($this->db->quoteName('#__extensions'))
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('template'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote((string) $style['template']))
				->where($this->db->quoteName('client_id') . ' = ' . $clientId)
		)->loadResult();
		if ($templateEnabled !== null && (int) $templateEnabled === 0) {
			return ToolResult::error('The template "' . $style['template'] . '" is disabled, so style ' . $id
				. ' cannot be made the default. Enable the template first (set_extension_enabled).');
		}

		$this->db->transactionStart();
		try {
			$reset = $this->db->getQuery(true)
				->update($this->db->quoteName('#__template_styles'))
				->set($this->db->quoteName('home') . ' = ' . $this->db->quote('0'))
				->where($this->db->quoteName('client_id') . ' = ' . $clientId)
				->where($this->db->quoteName('home') . ' = ' . $this->db->quote('1'));
			$this->db->setQuery($reset)->execute();

			$set = $this->db->getQuery(true)
				->update($this->db->quoteName('#__template_styles'))
				->set($this->db->quoteName('home') . ' = ' . $this->db->quote('1'))
				->where($this->db->quoteName('id') . ' = ' . $id);
			$this->db->setQuery($set)->execute();

			$this->db->transactionCommit();
		} catch (\Throwable $e) {
			$this->db->transactionRollback();
			throw $e;
		}

		return ToolResult::json(['ok' => true, 'id' => $id, 'template' => $style['template'], 'title' => $style['title'], 'client_id' => $clientId]);
	}
}
