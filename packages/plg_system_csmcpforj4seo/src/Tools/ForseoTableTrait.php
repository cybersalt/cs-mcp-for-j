<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools;

\defined('_JEXEC') or die;

/**
 * Shared table-name validation for every 4SEO tool. Refuses anything that
 * doesn't start with "forseo_" so a misuse can't poke at #__users, #__content
 * etc. Returns the unprefixed name (e.g. "forseo_rules") — callers wrap it
 * with $db->quoteName('#__' . $name) when building queries.
 */
trait ForseoTableTrait
{
	protected function resolveForseoTable(string $name): string
	{
		$name = trim($name);

		if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
			throw new \InvalidArgumentException('Invalid table name. Use only [a-zA-Z0-9_].');
		}

		if (strpos($name, 'forseo_') !== 0) {
			$name = 'forseo_' . $name;
		}

		$full   = $this->db->getPrefix() . $name;
		$exists = $this->db->setQuery('SHOW TABLES LIKE ' . $this->db->quote($full))->loadResult();

		if (!$exists) {
			throw new \InvalidArgumentException('Table not found: ' . $full);
		}

		return $name;
	}

	protected function listForseoTableNames(): array
	{
		$prefix = $this->db->getPrefix();
		$rows = $this->db->setQuery(
			'SHOW TABLES LIKE ' . $this->db->quote($prefix . 'forseo_%')
		)->loadColumn() ?: [];
		$out = [];
		foreach ($rows as $full) {
			if (str_starts_with($full, $prefix)) {
				$out[] = substr($full, strlen($prefix));
			}
		}
		sort($out);
		return $out;
	}

	protected function tableColumns(string $unprefixed): array
	{
		$full = $this->db->getPrefix() . $unprefixed;
		$rows = $this->db->setQuery('SHOW FULL COLUMNS FROM ' . $this->db->quoteName($full))->loadAssocList() ?: [];
		return $rows;
	}

	protected function findPrimaryKey(string $unprefixed): ?string
	{
		foreach ($this->tableColumns($unprefixed) as $row) {
			if (($row['Key'] ?? '') === 'PRI') {
				return (string) $row['Field'];
			}
		}
		return null;
	}
}
