<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Associations;
use Joomla\CMS\User\User;
use Joomla\Database\ParameterType;

/**
 * Language associations (the Associations tab) for articles and categories,
 * added in 2.10.0 (field review Q4, Tim 2026-10-28). Menu items and modules
 * already had them.
 *
 * AdminModel::save() writes #__associations from $data['associations']
 * (language tag => item id), but it does not check the linked items, which the
 * admin's modal picker does. So this checks each one exists, is in that
 * language, and that the caller may edit it, as in the Joomla admin.
 *
 * Kind is "article" or "category"; for a category, $extension is the
 * component the category belongs to (com_content, com_contact...).
 */
trait ItemAssociationsTrait
{
	private static function itemAssociationsSchema(string $kind): array
	{
		return [
			'type'        => 'object',
			'description' => 'Multilingual sites with item associations on: language tag => id of the ' . $kind
				. ' that translates this one, e.g. {"fr-FR": 123}. Replaces this ' . $kind . '\'s associations, like the '
				. 'Associations tab. This ' . $kind . ' needs a specific language (not "*"), each linked ' . $kind
				. ' must be in the language given, and you must be allowed to edit it.',
		];
	}

	/**
	 * @return array<string, int> Language tag => item id, for $data['associations'].
	 */
	private function resolveItemAssociations(User $actor, mixed $raw, string $kind, string $ownLanguage, int $ownId, string $extension = 'com_content'): array
	{
		if (!Associations::isEnabled()) {
			throw new \InvalidArgumentException(
				'associations need language associations to be on (System > Plugins > System - Language Filter > Item Associations).'
			);
		}

		if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
			throw new \InvalidArgumentException('associations must be an object of language tag => ' . $kind . ' id, e.g. {"fr-FR": 123}.');
		}

		if ($raw !== [] && ($ownLanguage === '' || $ownLanguage === '*')) {
			throw new \InvalidArgumentException('A ' . $kind . ' set to "All" languages cannot have associations. Set its language to a specific tag first (it can be done in the same call).');
		}

		$out = [];

		foreach ($raw as $tag => $id) {
			$tag = trim((string) $tag);
			$id  = (int) $id;

			if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) || $id <= 0) {
				throw new \InvalidArgumentException('associations must map language tags such as "fr-FR" to ' . $kind . ' ids; got ' . json_encode([$tag => $id]) . '.');
			}

			if ($tag === $ownLanguage) {
				continue;
			}

			if ($id === $ownId) {
				throw new \InvalidArgumentException('A ' . $kind . ' cannot be associated with itself.');
			}

			$row = $this->associationTarget($kind, $id, $extension);

			if ($row === null) {
				throw new \InvalidArgumentException('associations.' . $tag . ': no ' . $kind . ' with id ' . $id . ($kind === 'category' ? ' in ' . $extension : '') . '.');
			}

			if ($row['language'] !== $tag) {
				throw new \InvalidArgumentException('associations.' . $tag . ': ' . $kind . ' ' . $id . ' is in language "' . $row['language'] . '", not "' . $tag . '".');
			}

			if (!$this->canEditAssociationTarget($actor, $kind, $id, (int) $row['owner'], $extension)) {
				throw new \RuntimeException(
					'Permission denied: you are not allowed to edit ' . $kind . ' ' . $id . ', so you cannot link it as a translation. '
					. 'This is the same rule as in the Joomla admin.'
				);
			}

			$out[$tag] = $id;
		}

		return $out;
	}

	/**
	 * The item's current associations, for the get_* tools.
	 *
	 * @return array<string, int> Language tag => item id (this item excluded).
	 */
	private function storedItemAssociations(string $kind, int $id): array
	{
		$db      = $this->db;
		$context = $kind === 'category' ? 'com_categories.item' : 'com_content.item';
		$table   = $kind === 'category' ? '#__categories' : '#__content';

		$key = (string) $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName('key'))
				->from($db->quoteName('#__associations'))
				->where($db->quoteName('context') . ' = :context')
				->where($db->quoteName('id') . ' = :id')
				->bind(':context', $context)
				->bind(':id', $id, ParameterType::INTEGER)
		)->loadResult();

		if ($key === '') {
			return [];
		}

		$rows = (array) $db->setQuery(
			$db->getQuery(true)
				->select([$db->quoteName('i.id'), $db->quoteName('i.language')])
				->from($db->quoteName('#__associations', 'a'))
				->join('INNER', $db->quoteName($table, 'i') . ' ON ' . $db->quoteName('i.id') . ' = ' . $db->quoteName('a.id'))
				->where($db->quoteName('a.context') . ' = :context')
				->where($db->quoteName('a.key') . ' = :key')
				->where($db->quoteName('a.id') . ' <> :id')
				->bind(':context', $context)
				->bind(':key', $key)
				->bind(':id', $id, ParameterType::INTEGER)
		)->loadAssocList();

		$out = [];

		foreach ($rows as $r) {
			$out[(string) $r['language']] = (int) $r['id'];
		}

		ksort($out);

		return $out;
	}

	/** @return array{language: string, owner: int}|null */
	private function associationTarget(string $kind, int $id, string $extension): ?array
	{
		$db = $this->db;

		if ($kind === 'category') {
			$q = $db->getQuery(true)
				->select([$db->quoteName('language'), $db->quoteName('created_user_id', 'owner')])
				->from($db->quoteName('#__categories'))
				->where($db->quoteName('id') . ' = :id')
				->where($db->quoteName('extension') . ' = :ext')
				->bind(':ext', $extension);
		} else {
			$q = $db->getQuery(true)
				->select([$db->quoteName('language'), $db->quoteName('created_by', 'owner')])
				->from($db->quoteName('#__content'))
				->where($db->quoteName('id') . ' = :id');
		}

		$q->bind(':id', $id, ParameterType::INTEGER);
		$row = $db->setQuery($q)->loadAssoc();

		return $row ? ['language' => (string) $row['language'], 'owner' => (int) $row['owner']] : null;
	}

	/** Joomla's edit rule for one item: Edit, or Edit Own on an item you created. */
	private function canEditAssociationTarget(User $actor, string $kind, int $id, int $owner, string $extension): bool
	{
		$asset = $kind === 'category' ? $extension . '.category.' . $id : 'com_content.article.' . $id;

		if ($actor->authorise('core.edit', $asset)) {
			return true;
		}

		return $owner > 0 && $owner === (int) $actor->id && $actor->authorise('core.edit.own', $asset);
	}
}
