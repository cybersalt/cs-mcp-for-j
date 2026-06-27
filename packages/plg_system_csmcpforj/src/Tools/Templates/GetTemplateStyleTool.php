<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Templates;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * Fetch a single template style's row with its params decoded from JSON into
 * a proper object. Counterpart to list_template_styles (which returns the list
 * without params). The AI calls this before update_template_style so it can
 * read current values, change only what it needs to, and send the full merged
 * object back.
 */
final class GetTemplateStyleTool extends AbstractTool
{
	public function getName(): string { return 'get_template_style'; }

	public function getDescription(): string
	{
		return 'Fetch a single template style by id. Returns the row including the params blob '
			. 'decoded into a JSON object (Cassiopeia colour scheme, sticky header, font choice, '
			. 'etc; param names vary per template). Call list_template_styles first to find the '
			. 'id you want. Pair with update_template_style to write changes back.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => ['type' => 'integer', 'description' => 'Template style id from list_template_styles.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$id = $this->requirePositiveInt($arguments, 'id');

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'template', 'client_id', 'home', 'title', 'inheritable', 'parent', 'params']))
			->from($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('id') . ' = ' . $id);

		$row = $this->db->setQuery($query)->loadAssoc();
		if (!$row) {
			return ToolResult::error('Template style ' . $id . ' not found.');
		}

		// Decode params JSON into an associative array. If decoding fails (stored
		// as malformed JSON), surface the raw string so the AI can at least see it.
		$paramsRaw    = (string) ($row['params'] ?? '');
		$paramsParsed = $paramsRaw === '' ? new \stdClass() : json_decode($paramsRaw, true);
		if (!is_array($paramsParsed) && $paramsRaw !== '') {
			return ToolResult::json([
				'id'             => (int) $row['id'],
				'template'       => $row['template'],
				'client_id'      => (int) $row['client_id'],
				'home'           => (int) $row['home'],
				'title'          => $row['title'],
				'inheritable'    => (int) $row['inheritable'],
				'parent'         => (int) ($row['parent'] ?? 0),
				'params'         => null,
				'params_raw'     => $paramsRaw,
				'params_warning' => 'stored params did not decode as JSON; raw string returned for inspection',
			]);
		}

		return ToolResult::json([
			'id'          => (int) $row['id'],
			'template'    => $row['template'],
			'client_id'   => (int) $row['client_id'],
			'home'        => (int) $row['home'],
			'title'       => $row['title'],
			'inheritable' => (int) $row['inheritable'],
			'parent'      => (int) ($row['parent'] ?? 0),
			'params'      => $paramsParsed,
		]);
	}
}
