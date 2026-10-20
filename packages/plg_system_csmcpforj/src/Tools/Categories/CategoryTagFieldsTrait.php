<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Categories;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\Database\ParameterType;

/**
 * The edit-screen fields that the category and tag create/update tools share
 * beyond the basics: note, version note, author, created date, the JSON
 * option columns (params, metadata, and on tags images), and the parent
 * check. Read off Joomla 6.2.0's
 * administrator/components/com_categories/forms/category.xml,
 * administrator/components/com_content/forms/category.xml and
 * administrator/components/com_tags/forms/tag.xml.
 *
 * A trait in the core plugin rather than helpers on AbstractTool, because
 * add-ons extend AbstractTool too: a new protected method there would fatal
 * any add-on that already declares a private method with the same name.
 *
 * NO FORM FILTER RUNS. These tools call CategoryModel::save() and
 * TagModel::save() directly, so the form's validate= and filter= rules never
 * see the data. The checks below stand in for the ones that matter: the
 * robots list, the tag float list, image URL schemes, the CSS class rule on
 * tag_link_class, the user id rule on created_user_id, and `user_utc` on
 * created_time (dates are taken and stored as UTC).
 */
trait CategoryTagFieldsTrait
{
	/** The robots list on both forms, minus "" (Use Global), which is also accepted. */
	private static array $ctRobots = ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];

	/** The tag form's Images tab. Tags have no image_*_alt_empty or imgclass_* keys. */
	private static array $ctTagImageKeys = [
		'image_intro', 'float_intro', 'image_intro_alt', 'image_intro_caption',
		'image_fulltext', 'float_fulltext', 'image_fulltext_alt', 'image_fulltext_caption',
	];

	/** The media field's `schemes` attribute on both forms. */
	private static array $ctImageSchemes = ['http', 'https', 'ftp', 'ftps', 'data', 'file'];

	/** Wording appended to each JSON-column description. */
	private function ctMergeWording(bool $forUpdate): string
	{
		return $forUpdate
			? ' Pass only the keys to change: they are merged onto what is stored, a null value removes a key (back to the default), and keys you leave out keep their current values. Omit the whole argument to leave it untouched.'
			: ' Pass only the keys you want to set; the rest stay at Joomla\'s defaults.';
	}

	/** A nullable string property, for the inside of a JSON-column object. */
	private function ctText(string $description): array
	{
		return ['type' => 'string', 'description' => $description];
	}

	/** Schema for `metadata` (both forms: author and robots). */
	private function ctMetadataSchema(bool $forUpdate): array
	{
		return [
			'type'                 => 'object',
			'description'          => 'The Publishing tab metadata: author (meta author name) and robots.' . $this->ctMergeWording($forUpdate),
			'properties'           => [
				'author' => $this->ctText('Meta author name.'),
				'robots' => $this->ctText('Robots: "index, follow", "noindex, follow", "index, nofollow", "noindex, nofollow", or "" (or null) for the global setting.'),
			],
			'additionalProperties' => true,
		];
	}

	/** Schema for the category `params` column (the Options tab). */
	private function ctCategoryParamsSchema(bool $forUpdate): array
	{
		return [
			'type'                 => 'object',
			'description'          => 'The category Options: layout, image and, for article categories, the workflow. Components that add their own category options store them here too.' . $this->ctMergeWording($forUpdate),
			'properties'           => [
				'category_layout' => $this->ctText('Alternative layout for the category view, e.g. "_:blog" or "_:default" (a template override looks like "cassiopeia:mylayout"). "" uses the global setting.'),
				'image'           => $this->ctText('Category image: a site-relative path such as "images/headers/cat.jpg", or a full URL.'),
				'image_alt'       => $this->ctText('Alt text for the category image.'),
				'image_alt_empty' => ['type' => 'string', 'description' => 'The "No Description" box: "1" (or true) marks the image as decorative, so it gets an empty alt. "" (or false) unticks it.'],
				'workflow_id'     => ['type' => 'string', 'description' => 'Article categories only, when workflows are enabled: "use_default" (the default workflow), "inherit" (use the parent category\'s), or a workflow id.'],
			],
			'additionalProperties' => true,
		];
	}

	/** Schema for the tag `params` column. */
	private function ctTagParamsSchema(bool $forUpdate): array
	{
		return [
			'type'                 => 'object',
			'description'          => 'The tag Options: layout and link class.' . $this->ctMergeWording($forUpdate),
			'properties'           => [
				'tag_layout'     => $this->ctText('Alternative layout for the tag view, e.g. "_:default" or "_:list". "" uses the global setting.'),
				'tag_link_class' => $this->ctText('CSS class(es) on the tag\'s link wherever the tag is shown (e.g. "btn-info"). Space-separated.'),
			],
			'additionalProperties' => true,
		];
	}

	/** Schema for the tag `images` column (the Images tab). */
	private function ctTagImagesSchema(bool $forUpdate): array
	{
		$props = [];

		foreach (['intro' => 'intro (list view)', 'fulltext' => 'full (tag page)'] as $k => $where) {
			$props['image_' . $k]              = $this->ctText('The ' . $where . ' image: a site-relative path such as "images/tag.jpg", or a full URL.');
			$props['float_' . $k]              = $this->ctText('Float for the ' . $where . ' image: "right", "left", "none", or "" for the global setting.');
			$props['image_' . $k . '_alt']     = $this->ctText('Alt text for the ' . $where . ' image.');
			$props['image_' . $k . '_caption'] = $this->ctText('Caption under the ' . $where . ' image.');
		}

		return [
			'type'                 => 'object',
			'description'          => 'The Images tab: intro and full image, float, alt text and caption.' . $this->ctMergeWording($forUpdate),
			'properties'           => $props,
			'additionalProperties' => false,
		];
	}

	/** Schema for `created_time` (a category's created date). */
	private function ctCreatedTimeSchema(bool $forUpdate): array
	{
		return [
			'type'        => 'string',
			'description' => 'The created date, in UTC, as "YYYY-MM-DD HH:MM:SS" (the format get_category returns). A value with "Z" or an offset such as "+02:00" is converted to UTC; a value without one is taken as UTC.'
				. ($forUpdate ? ' Omit to keep the current value.' : ' Defaults to now.'),
		];
	}

	/**
	 * Validate a JSON-column argument and normalise its values to what the
	 * admin form would store. Returns a patch for mergeJsonPatch(): null
	 * values are kept (they delete the key).
	 *
	 * @param string $column  cat_params | tag_params | metadata | tag_images
	 */
	private function ctJsonArg(mixed $value, string $column, string $label): array
	{
		if ($value instanceof \stdClass) {
			$value = json_decode((string) json_encode($value), true);
		}

		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new McpException(-32602, $label . ' must be an object of key/value pairs; got ' . json_encode($value) . '.');
		}

		$allowed = $column === 'tag_images' ? self::$ctTagImageKeys : null;
		$patch   = [];

		foreach ($value as $key => $v) {
			$key = (string) $key;

			if ($allowed !== null && !in_array($key, $allowed, true)) {
				throw new McpException(-32602, $label . ': unknown key ' . json_encode($key) . '. Allowed: ' . implode(', ', $allowed) . '.');
			}

			if ($v === null) {
				$patch[$key] = null;
				continue;
			}

			// A component's own category option may be a nested value (a
			// subform); it is replaced whole. The known keys are all scalars.
			if (is_array($v) || is_object($v)) {
				if ($column === 'tag_images' || $this->ctKnownKey($column, $key)) {
					throw new McpException(-32602, $label . '.' . $key . ' must be a string; got ' . json_encode($v) . '.');
				}

				$patch[$key] = $v;
				continue;
			}

			$v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;

			$patch[$key] = $this->ctJsonValue($column, $key, $v, $label . '.' . $key);
		}

		return $patch;
	}

	private function ctKnownKey(string $column, string $key): bool
	{
		return match ($column) {
			'cat_params' => in_array($key, ['category_layout', 'image', 'image_alt', 'image_alt_empty', 'workflow_id'], true),
			'tag_params' => in_array($key, ['tag_layout', 'tag_link_class'], true),
			'metadata'   => in_array($key, ['author', 'robots'], true),
			default      => false,
		};
	}

	/** Per-key checks that mirror the forms' validate= rules. */
	private function ctJsonValue(string $column, string $key, string $v, string $label): string
	{
		if ($column === 'metadata') {
			if ($key === 'robots') {
				$v = trim($v);
				if ($v !== '' && !in_array($v, self::$ctRobots, true)) {
					throw new McpException(-32602, $label . ' must be one of ' . implode(', ', array_map('json_encode', self::$ctRobots)) . ', or "" for the global setting; got ' . json_encode($v) . '.');
				}
			}

			return $key === 'author' ? trim($v) : $v;
		}

		if ($column === 'cat_params') {
			switch ($key) {
				case 'image':
					$v = trim($v);
					$this->ctSafeImageUrl($v, $label);

					return $v;

				case 'image_alt_empty':
					// A checkbox: "1" when ticked, absent or "" when not.
					return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true) ? '1' : '';

				case 'workflow_id':
					$v = trim($v);
					if (!in_array($v, ['use_default', 'inherit'], true) && !ctype_digit($v)) {
						throw new McpException(-32602, $label . ' must be "use_default", "inherit" or a workflow id; got ' . json_encode($v) . '.');
					}

					return $v;

				case 'category_layout':
					return trim($v);
			}

			return $v;
		}

		if ($column === 'tag_params') {
			if ($key === 'tag_link_class') {
				$v = trim((string) preg_replace('/\s+/', ' ', $v));
				$this->ctCssClasses($v, $label);

				return $v;
			}

			return $key === 'tag_layout' ? trim($v) : $v;
		}

		if ($column === 'tag_images') {
			if ($key === 'image_intro' || $key === 'image_fulltext') {
				$v = trim($v);
				$this->ctSafeImageUrl($v, $label);

				return $v;
			}

			if ($key === 'float_intro' || $key === 'float_fulltext') {
				$v = trim($v);
				if (!in_array($v, ['', 'right', 'left', 'none'], true)) {
					throw new McpException(-32602, $label . ' must be "right", "left", "none", or "" for the global setting; got ' . json_encode($v) . '.');
				}

				return $v;
			}
		}

		return $v;
	}

	/** The CssIdentifier rule the tag form applies to tag_link_class. */
	private function ctCssClasses(string $value, string $label): void
	{
		if ($value === '') {
			return;
		}

		foreach (explode(' ', $value) as $class) {
			if (preg_match('/[^\x{002D}\x{0030}-\x{0039}\x{0040}-\x{005A}\x{005F}\x{003A}\x{0061}-\x{007A}\x{00A1}-\x{FFFF}]/u', $class)
				|| preg_match('/^[0-9]/', $class) || preg_match('/^(-[0-9])|^(--)/', $class)) {
				throw new McpException(-32602, $label . ': ' . json_encode($class) . ' is not a valid CSS class name.');
			}
		}
	}

	/** Image paths: relative, or one of the media field's schemes. Never a script URL. */
	private function ctSafeImageUrl(string $value, string $label): void
	{
		if ($value === '') {
			return;
		}

		if (preg_match('/[\x00-\x1F"<>]/', $value)) {
			throw new McpException(-32602, $label . ' contains characters a URL cannot have.');
		}

		if (preg_match('/^\s*([a-z][a-z0-9+.\-]*):/i', $value, $m) && !in_array(strtolower($m[1]), self::$ctImageSchemes, true)) {
			throw new McpException(-32602, $label . ': the "' . strtolower($m[1]) . ':" scheme is not allowed here.');
		}
	}

	/**
	 * The raw stored JSON columns of one row, read straight from the table so
	 * a patch merges onto exactly what is saved (not onto a model's
	 * getItem() view of it, which plugins may have added to).
	 *
	 * @param string[] $columns
	 *
	 * @return array<string, string>
	 */
	private function ctStoredColumns(string $table, int $id, array $columns): array
	{
		$db  = $this->db;
		$row = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName($columns))
				->from($db->quoteName($table))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $id, ParameterType::INTEGER)
		)->loadAssoc();

		$out = [];
		foreach ($columns as $column) {
			$out[$column] = (string) ($row[$column] ?? '');
		}

		return $out;
	}

	/**
	 * Merge a validated patch onto the stored column and return the JSON to
	 * save. Always a JSON object: an empty result is "{}", never "[]".
	 */
	private function ctMergeJson(mixed $stored, array $patch): string
	{
		return (string) json_encode((object) $this->mergeJsonPatch($stored, $patch));
	}

	/** A created date as a UTC SQL datetime. */
	private function ctDate(mixed $value, string $key): string
	{
		if (!is_string($value) || trim($value) === '') {
			throw new McpException(-32602, $key . ' must be a date string such as "2026-10-20 14:30:00" (UTC); got ' . json_encode($value) . '.');
		}

		$value = trim($value);

		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i', $value, $m)) {
			throw new McpException(-32602, $key . ' must look like "YYYY-MM-DD HH:MM:SS" (UTC), optionally with "Z" or an offset like "+02:00"; got ' . json_encode($value) . '.');
		}

		if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])
			|| (int) ($m[4] ?? 0) > 23 || (int) ($m[5] ?? 0) > 59 || (int) ($m[6] ?? 0) > 59) {
			throw new McpException(-32602, $key . ': ' . json_encode($value) . ' is not a real date and time.');
		}

		try {
			$date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
		} catch (\Exception $e) {
			throw new McpException(-32602, $key . ': could not read ' . json_encode($value) . ' as a date.');
		}

		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}

	/** `note`, `version_note`, `created_by_alias`: a string of at most 255 characters. */
	private function ctShortText(mixed $value, string $key): string
	{
		if (!is_string($value)) {
			throw new McpException(-32602, $key . ' must be a string; got ' . json_encode($value) . '.');
		}

		$value = trim($value);

		if (mb_strlen($value) > 255) {
			throw new McpException(-32602, $key . ' is limited to 255 characters; got ' . mb_strlen($value) . '.');
		}

		return $value;
	}

	/** `created_user_id`: the id of a user that exists (the form's UserId rule). */
	private function ctUserId(mixed $value, string $key): int
	{
		if (is_string($value) && ctype_digit(trim($value))) {
			$value = (int) trim($value);
		}

		if (!is_int($value) || $value <= 0) {
			throw new McpException(-32602, $key . ' must be a positive user id; got ' . json_encode($value) . '. Use list_users to find one.');
		}

		$db     = $this->db;
		$exists = (int) $db->setQuery(
			$db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName('#__users'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $value, ParameterType::INTEGER)
		)->loadResult();

		if ($exists === 0) {
			throw new McpException(-32602, $key . ': no user with id ' . $value . ' exists on this site. Use list_users to find one.');
		}

		return $value;
	}

	/**
	 * Check a new parent for a nested-set row (#__categories or #__tags): it
	 * must exist, be in the same extension (categories), and not be the item
	 * itself or one of its descendants. 1 (the root) is always allowed.
	 *
	 * @param string      $table      '#__categories' or '#__tags'
	 * @param int         $itemId     0 for a new item
	 * @param string|null $extension  the category's extension; null for tags
	 */
	private function ctCheckParent(string $table, int $parentId, int $itemId, ?string $extension): void
	{
		if ($parentId === 1) {
			return;
		}

		if ($parentId <= 0) {
			throw new McpException(-32602, 'parent_id must be a positive id (1 is the root).');
		}

		if ($itemId > 0 && $parentId === $itemId) {
			throw new McpException(-32602, 'parent_id cannot be the item itself.');
		}

		$db     = $this->db;
		$parent = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(array_merge(['id', 'lft', 'rgt'], $extension !== null ? ['extension'] : [])))
				->from($db->quoteName($table))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $parentId, ParameterType::INTEGER)
		)->loadAssoc();

		if (!$parent) {
			throw new McpException(-32602, 'parent_id ' . $parentId . ' does not exist.');
		}

		if ($extension !== null && (string) $parent['extension'] !== $extension) {
			throw new McpException(-32602, 'parent_id ' . $parentId . ' is a ' . $parent['extension'] . ' category; the parent must be a ' . $extension . ' category (or 1, the root).');
		}

		if ($itemId > 0) {
			$item = $db->setQuery(
				$db->getQuery(true)
					->select($db->quoteName(['lft', 'rgt']))
					->from($db->quoteName($table))
					->where($db->quoteName('id') . ' = :id')
					->bind(':id', $itemId, ParameterType::INTEGER)
			)->loadAssoc();

			if ($item && (int) $parent['lft'] > (int) $item['lft'] && (int) $parent['rgt'] < (int) $item['rgt']) {
				throw new McpException(-32602, 'parent_id ' . $parentId . ' is inside this item\'s own subtree; move that one first.');
			}
		}
	}

	/**
	 * Run a save with `version_note` where Joomla looks for it: the models'
	 * saveHistory() reads $data['version_note'] (the caller puts it there),
	 * while the Behaviour - Versionable plugin reads it only from the
	 * request's jform array. The request input is restored afterwards.
	 *
	 * @param callable(): mixed $save
	 */
	private function ctWithVersionNote(?string $note, callable $save): mixed
	{
		if ($note === null) {
			return $save();
		}

		$input    = $this->app()->getInput();
		$previous = $input->get('jform', null, 'raw');
		$input->set('jform', array_merge(is_array($previous) ? $previous : [], ['version_note' => $note]));

		try {
			return $save();
		} finally {
			$input->set('jform', $previous);
		}
	}

	/**
	 * For the get tools: decode JSON columns in place so they read as objects,
	 * the same shape the create/update tools take. A value that is not valid
	 * JSON is left as the raw string.
	 *
	 * @param string[] $columns
	 */
	private function ctDecodeJsonColumns(array $row, array $columns): array
	{
		foreach ($columns as $column) {
			if (!array_key_exists($column, $row) || !is_string($row[$column])) {
				continue;
			}

			if (trim($row[$column]) === '') {
				$row[$column] = new \stdClass();
				continue;
			}

			$decoded = json_decode($row[$column]);

			if ($decoded instanceof \stdClass) {
				$row[$column] = $decoded;
			} elseif (is_array($decoded) && $decoded === []) {
				$row[$column] = new \stdClass();
			}
		}

		return $row;
	}
}
