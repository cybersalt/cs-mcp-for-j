<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\Config;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Typed write to one row in 4SEO's scoped key-value config (#__forseo_config).
 *
 * The corresponding read tool, get_4seo_config, returns every row in the
 * table with values pre-decoded as JSON where applicable (the __parsed
 * convention). This tool is the typed write counterpart: pick a scope +
 * key, supply either a raw string value OR a JSON-encodable object (which
 * we encode for you), and the tool upserts the row.
 *
 * Why not query_4seo_table / update_4seo_row: those work but require the
 * agent to manually json_encode any nested-object value and to know
 * whether to use `value` (varchar 16000) or `large_value` (mediumtext)
 * based on size. This tool handles the encoding and the size-based column
 * routing automatically.
 */
final class Set4seoConfigTool extends AbstractTool
{
	/** 4SEO's value column is varchar(16000). Beyond that we spill into large_value. */
	private const VALUE_COLUMN_MAX = 16000;

	public function getName(): string { return 'set_4seo_config'; }

	public function getDescription(): string
	{
		return 'Set one 4SEO config key (#__forseo_config). Required: key. Optional: scope '
			. '(defaults to "default"), value (string), or value_object (anything JSON-encodable). '
			. 'Pass value OR value_object, not both. Strings ≤16000 bytes go in the `value` '
			. 'column; anything larger spills into `large_value` automatically. Upserts: '
			. 'creates the row if (scope,key) is new, updates in place otherwise.';
	}

	public function getInputSchema(): array
	{
		return [
			'type'     => 'object',
			'required' => ['key'],
			'properties' => [
				'key'          => ['type' => 'string', 'description' => 'Config key, e.g. "pages", "sitemaps". Use get_4seo_config to see what keys exist.'],
				'scope'        => ['type' => 'string', 'description' => 'Default "default". 4SEO uses scopes for per-user/per-site partitioning.'],
				'value'        => ['type' => 'string', 'description' => 'Raw string value. Pass this OR value_object, not both.'],
				'value_object' => ['description' => 'JSON-encodable value (object/array/scalar). Will be json_encode\'d automatically.'],
				'format'       => ['type' => 'string', 'description' => 'Optional value-format hint stored in the format column (e.g. "json"). Auto-detected if omitted.'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$key   = $this->requireString($arguments, 'key');
		$scope = (string) ($arguments['scope'] ?? 'default');

		$hasValue       = array_key_exists('value', $arguments);
		$hasValueObject = array_key_exists('value_object', $arguments);
		if ($hasValue && $hasValueObject) {
			return ToolResult::error('Pass value OR value_object, not both.');
		}
		if (!$hasValue && !$hasValueObject) {
			return ToolResult::error('Pass either value (string) or value_object (JSON-encodable).');
		}

		// Encode the supplied value to a string. Object/array → JSON; scalar → cast.
		if ($hasValueObject) {
			$encoded = json_encode($arguments['value_object'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				return ToolResult::error('Failed to JSON-encode value_object: ' . json_last_error_msg());
			}
			$format = (string) ($arguments['format'] ?? 'json');
		} else {
			$encoded = (string) $arguments['value'];
			$format  = (string) ($arguments['format'] ?? '');
		}

		// Size-based column routing — 4SEO stores small values in `value`
		// (varchar 16000) and overflow in `large_value` (mediumtext).
		$useLargeValue = strlen($encoded) > self::VALUE_COLUMN_MAX;
		$valueColVal      = $useLargeValue ? '' : $encoded;
		$largeValueColVal = $useLargeValue ? $encoded : '';

		$fullTable = $this->db->getPrefix() . 'forseo_config';

		// Upsert by (scope, key)
		$existsQuery = $this->db->getQuery(true)
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('scope') . ' = ' . $this->db->quote($scope))
			->where($this->db->quoteName('key') . ' = ' . $this->db->quote($key));
		$existingId = (int) $this->db->setQuery($existsQuery)->loadResult();

		$now = gmdate('Y-m-d H:i:s');

		if ($existingId > 0) {
			$update = $this->db->getQuery(true)
				->update($this->db->quoteName($fullTable))
				->set($this->db->quoteName('value') . ' = ' . $this->db->quote($valueColVal))
				->set($this->db->quoteName('large_value') . ' = ' . $this->db->quote($largeValueColVal))
				->set($this->db->quoteName('format') . ' = ' . $this->db->quote($format))
				->set($this->db->quoteName('modified_at') . ' = ' . $this->db->quote($now))
				->where($this->db->quoteName('id') . ' = ' . $existingId);
			$this->db->setQuery($update)->execute();

			return ToolResult::json([
				'ok'      => true,
				'action'  => 'updated',
				'row_id'  => $existingId,
				'scope'   => $scope,
				'key'     => $key,
				'format'  => $format,
				'stored_in' => $useLargeValue ? 'large_value' : 'value',
				'bytes'   => strlen($encoded),
			]);
		}

		$insert = $this->db->getQuery(true)
			->insert($this->db->quoteName($fullTable))
			->columns($this->db->quoteName(['scope', 'key', 'value', 'large_value', 'user_id', 'version', 'lock', 'lock_expires_at', 'format', 'modified_at']))
			->values(
				$this->db->quote($scope) . ', '
				. $this->db->quote($key) . ', '
				. $this->db->quote($valueColVal) . ', '
				. $this->db->quote($largeValueColVal) . ', '
				. (int) $actor->id . ', '
				. '0, '
				. $this->db->quote('') . ', '
				. $this->db->quote('1970-01-01 00:00:00') . ', '
				. $this->db->quote($format) . ', '
				. $this->db->quote($now)
			);
		$this->db->setQuery($insert)->execute();
		$id = (int) $this->db->insertid();

		return ToolResult::json([
			'ok'      => true,
			'action'  => 'inserted',
			'row_id'  => $id,
			'scope'   => $scope,
			'key'     => $key,
			'format'  => $format,
			'stored_in' => $useLargeValue ? 'large_value' : 'value',
			'bytes'   => strlen($encoded),
		]);
	}
}
