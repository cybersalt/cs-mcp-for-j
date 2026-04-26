<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Sets a template style as the default ("home") style for its client. Equivalent
 * to clicking the home star icon in Templates → Styles.
 */
final class SetDefaultTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'set_default_template_style'; }

	public function getDescription(): string
	{
		return 'Make a template style the default for its client (site or admin). Use list_template_styles to find the id.';
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

		$this->db->transactionStart();
		try {
			$reset = $this->db->getQuery(true)
				->update($this->db->quoteName('#__template_styles'))
				->set($this->db->quoteName('home') . ' = ' . $this->db->quote('0'))
				->where($this->db->quoteName('client_id') . ' = ' . $clientId);
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
