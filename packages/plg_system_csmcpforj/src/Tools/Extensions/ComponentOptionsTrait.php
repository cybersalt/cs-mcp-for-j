<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Shared by get_component_params and set_component_params: name validation,
 * the #__extensions row lookup, and reading the option list a component
 * declares in administrator/components/<component>/config.xml (the form
 * Joomla shows behind the Options button).
 *
 * Expects to be used inside an AbstractTool (needs $this->db).
 */
trait ComponentOptionsTrait
{
	/**
	 * Lower-cases and validates a component name. Strict on purpose: the value
	 * is concatenated into a filesystem path to find config.xml.
	 */
	private function requireComponentName(array $arguments): string
	{
		$component = strtolower(trim((string) ($arguments['component'] ?? '')));

		if ($component === '') {
			throw new \InvalidArgumentException('component is required, e.g. "com_content".');
		}

		if (preg_match('/^com_[a-z0-9_]+$/', $component) !== 1) {
			throw new \InvalidArgumentException(
				'Invalid component "' . $component . '". Expected a name like "com_content" '
				. '(com_ followed by lowercase letters, digits or underscores).'
			);
		}

		return $component;
	}

	/**
	 * The component's #__extensions row, or null when it is not installed.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadComponentRow(string $component): ?array
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['extension_id', 'name', 'element', 'enabled', 'protected', 'locked', 'params']))
			->from($this->db->quoteName('#__extensions'))
			->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
			->where($this->db->quoteName('element') . ' = ' . $this->db->quote($component));

		$row = $this->db->setQuery($query)->loadAssoc();

		return $row ?: null;
	}

	/**
	 * Decode a stored params blob into an array (empty array when blank or
	 * not valid JSON).
	 *
	 * @return array<string, mixed>
	 */
	private function decodeParams(?string $raw): array
	{
		if ($raw === null || $raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * Read the options declared in the component's config.xml.
	 *
	 * Returns null when the component ships no config.xml (it then has no
	 * Options button). The permissions field (type="rules") and fields that
	 * hold no value (note, spacer) are left out. A field inside
	 * <fields name="x"> is reported as "x.field" because Joomla stores it
	 * nested under the "x" key.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function readConfigXmlOptions(string $component): ?array
	{
		$file = JPATH_ADMINISTRATOR . '/components/' . $component . '/config.xml';

		if (!is_file($file)) {
			return null;
		}

		$xml = @simplexml_load_file($file, \SimpleXMLElement::class, LIBXML_NONET);

		if ($xml === false) {
			return null;
		}

		$this->loadComponentLanguage($component);

		$options = [];

		foreach ($xml->xpath('//field[@name]') ?: [] as $field) {
			$type = strtolower((string) ($field['type'] ?? 'text'));

			if (in_array($type, ['rules', 'note', 'spacer'], true)) {
				continue;
			}

			$groups = [];

			foreach ($field->xpath('ancestor::fields[@name]') ?: [] as $group) {
				$groups[] = (string) $group['name'];
			}

			$fieldset = $field->xpath('ancestor::fieldset[@name][1]') ?: [];
			$label    = (string) ($field['label'] ?? '');

			$entry = [
				'name'     => implode('.', array_merge($groups, [(string) $field['name']])),
				'label'    => $label !== '' ? Text::_($label) : '',
				'type'     => $type,
				'default'  => isset($field['default']) ? (string) $field['default'] : null,
				'fieldset' => $fieldset ? (string) $fieldset[0]['name'] : null,
			];

			if (in_array(strtolower((string) ($field['multiple'] ?? '')), ['true', '1', 'multiple'], true)) {
				$entry['multiple'] = true;
			}

			$choices = [];

			foreach ($field->option ?? [] as $option) {
				$text      = trim((string) $option);
				$choices[] = [
					'value' => (string) ($option['value'] ?? ''),
					'label' => $text !== '' ? Text::_($text) : '',
				];
			}

			if ($choices) {
				$entry['choices'] = $choices;
			}

			$options[] = $entry;
		}

		return $options;
	}

	/**
	 * Load the strings config.xml labels point at: the core admin strings
	 * (most com_content labels are JGLOBAL_*) and the component's own admin
	 * language file, from either the shared or the component's language folder.
	 */
	private function loadComponentLanguage(string $component): void
	{
		try {
			$lang = Factory::getApplication()->getLanguage();
			$lang->load('joomla', JPATH_ADMINISTRATOR, null, false, true);
			$lang->load($component, JPATH_ADMINISTRATOR, null, false, true)
				|| $lang->load($component, JPATH_ADMINISTRATOR . '/components/' . $component, null, false, true);
		} catch (\Throwable $e) {
			// Labels fall back to their language keys; the option names still work.
		}
	}
}
