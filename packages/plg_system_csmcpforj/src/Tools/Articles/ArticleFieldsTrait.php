<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\Articles;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\Database\ParameterType;

/**
 * The article edit-screen fields that create_article and update_article share
 * beyond the basics: the Images and Links tab (`images`, `urls`), the Options
 * tab (`attribs`), the Publishing tab's metadata (`metadata`) and dates, the
 * note and the version note. Read off Joomla 6.2.0's
 * administrator/components/com_content/forms/article.xml.
 *
 * A trait in the core plugin rather than helpers on AbstractTool, for the
 * same reason as ArticleAuthorTrait: add-ons extend AbstractTool too.
 *
 * DATES ARE UTC. Every date argument is stored exactly as given (after
 * conversion to UTC when it carries an offset), because these tools call
 * ArticleModel::save() directly and no form filter runs. The admin form's
 * `filter="user_utc"` converts the editor's local time to UTC before the same
 * save; skipping the form means we must hand over UTC ourselves. A value with
 * no offset is taken as UTC, which is also what get_article returns, so a
 * value read from get_article can be written back unchanged.
 */
trait ArticleFieldsTrait
{
	/** The Images and Links tab, image half (Joomla 6.2 added the two imgclass keys). */
	private static array $articleImageKeys = [
		'image_intro', 'image_intro_alt', 'image_intro_alt_empty', 'imgclass_intro', 'float_intro', 'image_intro_caption',
		'image_fulltext', 'image_fulltext_alt', 'image_fulltext_alt_empty', 'imgclass_fulltext', 'float_fulltext', 'image_fulltext_caption',
	];

	/** The Images and Links tab, links half. */
	private static array $articleUrlKeys = [
		'urla', 'urlatext', 'targeta',
		'urlb', 'urlbtext', 'targetb',
		'urlc', 'urlctext', 'targetc',
	];

	/** The Publishing tab's robots list, minus "" (Use Global), which is also accepted. */
	private static array $articleRobots = ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];

	/**
	 * Schema properties for the shared fields. $forUpdate switches the wording
	 * between "merged onto what is stored" and "set on the new article".
	 */
	private function articleFieldsSchema(bool $forUpdate): array
	{
		$merge = $forUpdate
			? ' Pass only the keys to change: they are merged onto what is stored, a null value removes a key (back to the default), and keys you leave out keep their current values.'
			: ' Pass only the keys you want to set; the rest stay at Joomla\'s defaults.';

		$date = static fn (string $what, bool $nullable): array => [
			'type'        => 'string',
			'description' => $what . ' In UTC, as "YYYY-MM-DD HH:MM:SS" (the format get_article returns). A value with "Z" or an offset such as "+02:00" is converted to UTC; a value without one is taken as UTC.'
				. ($nullable ? ' "" (or null) clears it.' : '')
				. ($forUpdate ? ' Omit to keep the current value.' : ''),
		];

		$text = static fn (string $what): array => ['type' => 'string', 'description' => $what];

		$imageProps = [];
		foreach (['intro' => 'intro (blog/list view)', 'fulltext' => 'full article'] as $k => $where) {
			$imageProps['image_' . $k]              = $text('The ' . $where . ' image: a site-relative path such as "images/photo.jpg", or a full URL.');
			$imageProps['image_' . $k . '_alt']     = $text('Alt text for the ' . $where . ' image.');
			$imageProps['image_' . $k . '_alt_empty'] = ['type' => 'string', 'description' => 'The "No Description" box: "1" (or true) marks the ' . $where . ' image as decorative, so it gets an empty alt. "" (or false) unticks it.'];
			$imageProps['imgclass_' . $k]           = $text('CSS class(es) on the ' . $where . ' <img> itself (Joomla 6.2+ "Image Class"). Space-separated. "" uses the global setting.');
			$imageProps['float_' . $k]              = $text('CSS class(es) on the ' . $where . ' image\'s <figure> wrapper ("Figure Class", e.g. "float-start"). Space-separated. "" uses the global setting.');
			$imageProps['image_' . $k . '_caption'] = $text('Caption under the ' . $where . ' image.');
		}

		$urlProps = [];
		foreach (['a', 'b', 'c'] as $l) {
			$L = strtoupper($l);
			$urlProps['url' . $l]          = $text('Link ' . $L . ' URL.');
			$urlProps['url' . $l . 'text'] = $text('Link ' . $L . ' text.');
			$urlProps['target' . $l]       = ['type' => 'string', 'description' => 'Link ' . $L . ' target: "0" same window, "1" new window, "2" popup, "3" modal. "" uses the global setting.'];
		}

		return [
			'note'          => ['type' => 'string', 'description' => 'Admin-only note shown under the title in the Articles list. Up to 255 characters. "" clears it.' . ($forUpdate ? ' Omit to keep it.' : '')],
			'version_note'  => ['type' => 'string', 'description' => 'A note stored with this save in the article\'s Versions history (only kept when the site has version history on). Up to 255 characters.'],
			'created'       => $date('The created date.', false),
			'publish_up'    => $date('Start Publishing. A published article with no start date gets "now" when it is saved.', true),
			'publish_down'  => $date('Finish Publishing.', true),
			'featured_up'   => $date('Start Featured. Only applies while the article is featured.', true),
			'featured_down' => $date('Finish Featured. Only applies while the article is featured.', true),
			'images'        => [
				'type'                 => 'object',
				'description'          => 'The Images and Links tab: intro and full-text images, their alt text, image class, figure class and caption.' . $merge,
				'properties'           => $imageProps,
				'additionalProperties' => false,
			],
			'urls'          => [
				'type'                 => 'object',
				'description'          => 'The Images and Links tab: links A, B and C with their text and target.' . $merge,
				'properties'           => $urlProps,
				'additionalProperties' => false,
			],
			'attribs'       => [
				'type'                 => 'object',
				'description'          => 'The Options tab (how this article displays), as key/value pairs. Common keys: show_title, link_titles, show_tags, show_intro, info_block_position, info_block_show_title, show_category, link_category, show_parent_category, link_parent_category, show_associations, flags, show_author, link_author, show_create_date, show_modify_date, show_publish_date, show_item_navigation, show_vote, show_hits, show_noauth, urls_position, alternative_readmore, article_layout, article_page_title (browser page title), show_publishing_options, show_article_options, show_urls_images_backend, show_urls_images_frontend. Show/hide values are "1" and "0"; "" uses the global setting. get_article shows the current values.' . $merge,
				'additionalProperties' => true,
			],
			'metadata'      => [
				'type'                 => 'object',
				'description'          => 'The Publishing tab metadata: robots ("index, follow", "noindex, follow", "index, nofollow", "noindex, nofollow", or "" for the global setting), author (meta author name) and rights (content rights).' . $merge,
				'properties'           => [
					'robots' => $text('Robots: "index, follow", "noindex, follow", "index, nofollow", "noindex, nofollow", or "" for the global setting.'),
					'author' => $text('Meta author name.'),
					'rights' => $text('Content rights statement.'),
				],
				'additionalProperties' => true,
			],
		];
	}

	/**
	 * A date argument as a UTC SQL datetime, or null when cleared.
	 */
	private function articleDate(mixed $value, string $key, bool $nullable): ?string
	{
		if ($value === null || $value === '') {
			if ($nullable) {
				return null;
			}

			throw new McpException(-32602, $key . ' cannot be cleared; pass a date such as "2026-10-20 14:30:00" (UTC).');
		}

		if (!is_string($value)) {
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

	/** `note` and `version_note`: a string of at most 255 characters. */
	private function articleShortText(mixed $value, string $key): string
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

	/**
	 * Validate one of the JSON-column arguments and normalise its values to
	 * what the admin form would store. Returns a patch for mergeJsonPatch():
	 * null values are kept (they delete the key).
	 *
	 * @param string $column images | urls | attribs | metadata
	 */
	private function articleJsonArg(mixed $value, string $column): array
	{
		if ($value instanceof \stdClass) {
			$value = json_decode((string) json_encode($value), true);
		}

		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new McpException(-32602, $column . ' must be an object of key/value pairs; got ' . json_encode($value) . '.');
		}

		$allowed = match ($column) {
			'images' => self::$articleImageKeys,
			'urls'   => self::$articleUrlKeys,
			default  => null,
		};

		$patch = [];

		foreach ($value as $key => $v) {
			$key = (string) $key;

			if ($allowed !== null && !in_array($key, $allowed, true)) {
				throw new McpException(-32602, $column . ': unknown key ' . json_encode($key) . '. Allowed: ' . implode(', ', $allowed) . '.');
			}

			if ($v === null) {
				$patch[$key] = null;
				continue;
			}

			// attribs may carry a plugin's nested option (a subform); replaced whole.
			if (is_array($v) || is_object($v)) {
				if ($column !== 'attribs') {
					throw new McpException(-32602, $column . '.' . $key . ' must be a string; got ' . json_encode($v) . '.');
				}

				$patch[$key] = $v;
				continue;
			}

			$v = is_bool($v) ? ($v ? '1' : '0') : (string) $v;

			$patch[$key] = $this->articleJsonValue($column, $key, $v);
		}

		return $patch;
	}

	/** Per-key checks that mirror the form's validate= rules. */
	private function articleJsonValue(string $column, string $key, string $v): string
	{
		$label = $column . '.' . $key;

		if ($column === 'images') {
			if (str_ends_with($key, '_alt_empty')) {
				// A checkbox: "1" when ticked, absent or "" when not.
				return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true) ? '1' : '';
			}

			if (str_starts_with($key, 'imgclass_') || str_starts_with($key, 'float_')) {
				$v = trim((string) preg_replace('/\s+/', ' ', $v));
				$this->articleCssClasses($v, $label);

				return $v;
			}

			if ($key === 'image_intro' || $key === 'image_fulltext') {
				$v = trim($v);
				$this->articleSafeUrl($v, $label, ['http', 'https', 'ftp', 'ftps', 'data', 'file']);

				return $v;
			}

			return $v;
		}

		if ($column === 'urls') {
			if (str_starts_with($key, 'target')) {
				$v = trim($v);
				if (!in_array($v, ['', '0', '1', '2', '3'], true)) {
					throw new McpException(-32602, $label . ' must be "0" (same window), "1" (new window), "2" (popup), "3" (modal) or "" (global setting); got ' . json_encode($v) . '.');
				}

				return $v;
			}

			if (in_array($key, ['urla', 'urlb', 'urlc'], true)) {
				$v = trim($v);
				$this->articleSafeUrl($v, $label, null);

				// What ArticleModel::save() does to a posted link, minus its
				// read of the raw form post, which this request does not have.
				return ($v === '' || str_starts_with($v, '#')) ? $v : PunycodeHelper::urlToPunycode($v);
			}

			return $v;
		}

		if ($column === 'metadata') {
			if ($key === 'robots') {
				$v = trim($v);
				if ($v !== '' && !in_array($v, self::$articleRobots, true)) {
					throw new McpException(-32602, 'metadata.robots must be one of ' . implode(', ', array_map('json_encode', self::$articleRobots)) . ', or "" for the global setting; got ' . json_encode($v) . '.');
				}
			}

			if ($key === 'author') {
				$v = trim($v);
			}

			return $v;
		}

		return $v;
	}

	/** The CssIdentifier rule the form applies to imgclass_* and float_*. */
	private function articleCssClasses(string $value, string $label): void
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

	/**
	 * Refuse script URLs. $schemes limits which schemes are allowed at all
	 * (null: any except the script ones); relative paths are always fine.
	 */
	private function articleSafeUrl(string $value, string $label, ?array $schemes): void
	{
		if ($value === '') {
			return;
		}

		if (preg_match('/[\x00-\x1F"<>]/', $value)) {
			throw new McpException(-32602, $label . ' contains characters a URL cannot have.');
		}

		if (preg_match('/^\s*([a-z][a-z0-9+.\-]*):/i', $value, $m)) {
			$scheme = strtolower($m[1]);

			if (in_array($scheme, ['javascript', 'vbscript'], true) || ($schemes === null && $scheme === 'data')
				|| ($schemes !== null && !in_array($scheme, $schemes, true))) {
				throw new McpException(-32602, $label . ': the "' . $scheme . ':" scheme is not allowed here.');
			}
		}
	}

	/**
	 * The featured dates as stored in #__content_frontpage (UTC), or nulls when
	 * the article is not featured.
	 *
	 * @return array{featured_up: ?string, featured_down: ?string}
	 */
	private function storedFeaturedDates(int $articleId): array
	{
		$db  = $this->db;
		$row = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['featured_up', 'featured_down']))
				->from($db->quoteName('#__content_frontpage'))
				->where($db->quoteName('content_id') . ' = :id')
				->bind(':id', $articleId, ParameterType::INTEGER)
		)->loadAssoc();

		return [
			'featured_up'   => $row['featured_up'] ?? null,
			'featured_down' => $row['featured_down'] ?? null,
		];
	}

	/**
	 * The dates as stored on the row, for the read-back check.
	 *
	 * @return array<string, ?string>
	 */
	private function storedArticleDates(int $articleId): array
	{
		$db  = $this->db;
		$row = $db->setQuery(
			$db->getQuery(true)
				->select($db->quoteName(['created', 'publish_up', 'publish_down']))
				->from($db->quoteName('#__content'))
				->where($db->quoteName('id') . ' = :id')
				->bind(':id', $articleId, ParameterType::INTEGER)
		)->loadAssoc();

		return ($row ?: []) + $this->storedFeaturedDates($articleId);
	}

	/**
	 * Compare the dates asked for with what landed. Returns a warning or null.
	 * Joomla legitimately changes some (a published article with no start date
	 * gets "now"; a finish date before the start date is swapped; the featured
	 * dates are skipped when a workflow manages featuring), so a mismatch is
	 * reported, not treated as a failed save.
	 *
	 * @param array<string, ?string> $asked
	 */
	private function articleDateWarning(int $articleId, array $asked): ?string
	{
		if ($asked === []) {
			return null;
		}

		$stored = $this->storedArticleDates($articleId);
		$wrong  = [];

		foreach ($asked as $key => $value) {
			$have = $stored[$key] ?? null;
			$have = ($have === null || $have === '') ? null : substr((string) $have, 0, 19);

			if ($have !== $value) {
				$wrong[] = $key . ' is ' . json_encode($have) . ', not ' . json_encode($value);
			}
		}

		return $wrong === [] ? null
			: 'Saved, but Joomla stored different dates (all UTC): ' . implode('; ', $wrong) . '. '
				. 'A published article with no start date gets "now"; a finish date earlier than the start date is swapped; '
				. 'featured dates are not applied when a workflow manages featuring.';
	}

	/**
	 * Run a save with `version_note` where both Joomla 5 and 6 look for it:
	 * Joomla 6's ArticleModel reads $data['version_note'], while Joomla 5's
	 * Behaviour - Versionable plugin reads it only from the request's jform
	 * array. The request input is restored afterwards.
	 *
	 * @param callable(): array $save
	 */
	private function withArticleVersionNote(?string $note, callable $save): array
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
}
