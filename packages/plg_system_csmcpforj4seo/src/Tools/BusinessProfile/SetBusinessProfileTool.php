<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\BusinessProfile;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Set 4SEO's Business Profile / site-wide LocalBusiness config.
 *
 * Storage: #__forseo_config row (scope='default', key='sd'). The value column
 * holds JSON. Schema is determined by 4SEO's config/sd.php — keys live there
 * as flat camelCase entries (organizationName, organizationTel, ...) plus a
 * 28-field hours grid (hoursMon1Opens, hoursMon1Closes, hoursMon2Opens, ...).
 *
 * Why this is a typed wrapper instead of plain set_4seo_config:
 *  1. Chicken-and-egg: 4SEO only creates the (default,sd) row when a human
 *     saves the form. This tool creates it from scratch with sane defaults.
 *  2. organizationType and organizationAddressCountry are stored as single-
 *     element ARRAYS, not strings (the renderer does array_pop). Easy to
 *     get wrong by hand.
 *  3. organizationLogo is an object with width/height. Plain URL gets
 *     auto-normalised.
 *  4. opening_hours is a clean array of {day, opens, closes} entries; the
 *     tool fans these out across the 28-field grid and sets
 *     organizationHoursType=3 (CUSTOM) automatically.
 *  5. Merge-by-default: untouched fields preserve whatever 4SEO already had,
 *     so the agent can patch one field without nuking the rest.
 *
 * Rendered effect: 4SEO's hooks emit a LocalBusiness JSON-LD node at
 * https://yoursite/#defaultBusiness on every page, populated from these
 * fields. No cache clear needed — values are read per request.
 */
final class SetBusinessProfileTool extends AbstractTool
{
	/** 4SEO config value column is varchar(16000); overflow spills to large_value. */
	private const VALUE_COLUMN_MAX = 16000;

	/** Renderer constants from 4SEO's data/sd.php. */
	private const HOURS_TYPE_NONE     = 0;
	private const HOURS_TYPE_WEEKDAYS = 1;
	private const HOURS_TYPE_ALWAYS   = 2;
	private const HOURS_TYPE_CUSTOM   = 3;

	private const DAY_SHORTS = [
		'Mon' => ['monday', 'mon'],
		'Tue' => ['tuesday', 'tue', 'tues'],
		'Wed' => ['wednesday', 'wed'],
		'Thu' => ['thursday', 'thu', 'thur', 'thurs'],
		'Fri' => ['friday', 'fri'],
		'Sat' => ['saturday', 'sat'],
		'Sun' => ['sunday', 'sun'],
	];

	/**
	 * Default config baseline from 4SEO's config/sd.php (excluding doNotStore
	 * keys: imageSpec, logoSpec, profileImageSpec, imageDetectionMethod,
	 * faqPageItemAllowedTags, removeJoomlaBreadcrumb).
	 *
	 * Used only when creating the row from scratch. Existing-row updates
	 * leave any of these alone unless the caller overrides them.
	 */
	private function defaults(): array
	{
		$app = Factory::getApplication();
		$siteUrl = rtrim((string) $app->get('live_site', ''), '/');
		if ($siteUrl === '') {
			$siteUrl = (string) \Joomla\CMS\Uri\Uri::root();
		}

		return [
			'enabled'                        => true,
			'enabledSiteLinks'               => true,
			'enabledBreadcrumb'              => true,
			'enabledPerPage'                 => true,
			'enabledLocalBusiness'           => true,
			'enabledBuiltInRules'            => true,
			'enabledCleanup'                 => true,
			'organizationType'               => ['LocalBusiness'],
			'organizationName'               => (string) $app->get('sitename', ''),
			'organizationUrl'                => $siteUrl,
			'organizationTel'                => '',
			'organizationPriceRange'         => '',
			'organizationStreetAddress'      => '',
			'organizationAddressLocality'    => '',
			'organizationAddressRegion'      => '',
			'organizationPostalCode'         => '',
			'organizationAddressCountry'     => [],
			'organizationGeoLatitude'        => '',
			'organizationGeoLongitude'       => '',
			'organizationHoursType'          => self::HOURS_TYPE_NONE,
			'organizationHoursHoursType'     => 0,
			'hoursMon1Opens' => '09:00', 'hoursMon1Closes' => '17:00', 'hoursMon2Opens' => '', 'hoursMon2Closes' => '',
			'hoursTue1Opens' => '09:00', 'hoursTue1Closes' => '17:00', 'hoursTue2Opens' => '', 'hoursTue2Closes' => '',
			'hoursWed1Opens' => '09:00', 'hoursWed1Closes' => '17:00', 'hoursWed2Opens' => '', 'hoursWed2Closes' => '',
			'hoursThu1Opens' => '09:00', 'hoursThu1Closes' => '17:00', 'hoursThu2Opens' => '', 'hoursThu2Closes' => '',
			'hoursFri1Opens' => '09:00', 'hoursFri1Closes' => '17:00', 'hoursFri2Opens' => '', 'hoursFri2Closes' => '',
			'hoursSat1Opens' => '', 'hoursSat1Closes' => '', 'hoursSat2Opens' => '', 'hoursSat2Closes' => '',
			'hoursSun1Opens' => '', 'hoursSun1Closes' => '', 'hoursSun2Opens' => '', 'hoursSun2Closes' => '',
			'organizationLogo'               => [],
			'personName'                     => '',
			'personUrl'                      => $siteUrl,
			'googleMapsApiKey'               => '',
			'organizationCustomCode'         => '',
			'personCustomCode'               => '',
		];
	}

	public function getName(): string { return 'set_4seo_business_profile'; }

	public function getDescription(): string
	{
		return 'Set 4SEO\'s site-wide LocalBusiness profile — the data 4SEO renders into the '
			. '#defaultBusiness JSON-LD node on every page. Merges into the existing #__forseo_config '
			. '(scope=default, key=sd) row, or creates it from scratch if the human has never saved '
			. 'the Business Profile form. Accepts: business_type (schema.org type string e.g. '
			. '"LocalBusiness", "Optician", "MedicalBusiness", "Restaurant"), name, url, telephone, '
			. 'price_range, address (object {streetAddress, addressLocality, addressRegion, '
			. 'postalCode, addressCountry}), latitude, longitude, logo (URL string OR object '
			. '{url, width, height}), opening_hours (array of {day, opens "HH:MM", closes "HH:MM"} '
			. '— day accepts Monday/Mon/monday). Supplying opening_hours implicitly sets '
			. 'organizationHoursType=3 (CUSTOM). Optional: enabled, custom_jsonld (object or JSON '
			. 'string merged into the rendered #defaultBusiness), person_name, person_url. Untouched '
			. 'fields are preserved. After this call, fetch the home page with fetch_rendered_url '
			. 'and confirm the #defaultBusiness node has a non-empty telephone + resolving address.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'business_type' => ['type' => 'string', 'description' => 'Schema.org type for the LocalBusiness node (e.g. "Optician", "Restaurant", "LocalBusiness"). 4SEO\'s renderer accepts any schema.org LocalBusiness sub-type — see data/sd.php LOCAL_BUSINESS_TYPES.'],
				'name'          => ['type' => 'string'],
				'url'           => ['type' => 'string'],
				'telephone'     => ['type' => 'string'],
				'price_range'   => ['type' => 'string', 'description' => 'e.g. "$$" or "CAD 50-150". Free text.'],
				'address' => [
					'type' => 'object',
					'properties' => [
						'streetAddress'   => ['type' => 'string'],
						'addressLocality' => ['type' => 'string'],
						'addressRegion'   => ['type' => 'string'],
						'postalCode'      => ['type' => 'string'],
						'addressCountry'  => ['type' => 'string', 'description' => '2-letter ISO code, e.g. "CA".'],
					],
					'additionalProperties' => false,
				],
				'latitude'      => ['description' => 'Decimal degrees, number or string. Required alongside longitude for the GeoCoordinates node to render.'],
				'longitude'     => ['description' => 'Decimal degrees, number or string. Required alongside latitude.'],
				'logo' => [
					'description' => 'URL string OR object {url, width, height}. Renders as the LocalBusiness.image / Organization.logo ImageObject.',
				],
				'opening_hours' => [
					'type' => 'array',
					'description' => 'Array of {day, opens, closes}. Each day accepts up to 2 slots (e.g. lunch break). day accepts "Monday"/"Mon"/"monday". Supplying this sets organizationHoursType=3 (CUSTOM).',
					'items' => [
						'type' => 'object',
						'required' => ['day', 'opens', 'closes'],
						'properties' => [
							'day'    => ['type' => 'string'],
							'opens'  => ['type' => 'string', 'description' => 'HH:MM 24-hour.'],
							'closes' => ['type' => 'string', 'description' => 'HH:MM 24-hour.'],
						],
					],
				],
				'enabled'              => ['type' => 'boolean', 'description' => 'Master enable flag for 4SEO\'s structured-data block. Defaults true.'],
				'enabled_local_business' => ['type' => 'boolean', 'description' => 'Toggle just the LocalBusiness node without disabling the rest of 4SEO\'s SD.'],
				'custom_jsonld'        => ['description' => 'Object or JSON string. Merged into the rendered #defaultBusiness node (e.g. add areaServed, sameAs, etc.).'],
				'person_name'          => ['type' => 'string'],
				'person_url'           => ['type' => 'string'],
				'mode'                 => ['type' => 'string', 'enum' => ['merge', 'replace'], 'description' => 'Default merge (preserve untouched keys). replace wipes everything back to defaults before applying.'],
				'google_maps_api_key'  => ['type' => 'string'],
			],
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'write'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$mode = (string) ($arguments['mode'] ?? 'merge');
		if (!in_array($mode, ['merge', 'replace'], true)) {
			return ToolResult::error('mode must be merge or replace.');
		}

		// Validate opening_hours up front so the partial-write-on-bad-input case can't happen.
		$hoursPatch = null;
		if (array_key_exists('opening_hours', $arguments)) {
			[$hoursPatch, $hoursErr] = $this->buildHoursPatch($arguments['opening_hours']);
			if ($hoursErr !== null) {
				return ToolResult::error($hoursErr);
			}
		}

		// Validate logo shape.
		$logoPatch = null;
		if (array_key_exists('logo', $arguments)) {
			[$logoPatch, $logoErr] = $this->normaliseLogo($arguments['logo']);
			if ($logoErr !== null) {
				return ToolResult::error($logoErr);
			}
		}

		$fullTable = $this->db->getPrefix() . 'forseo_config';

		// Read existing row.
		$existsQuery = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'value', 'large_value']))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('scope') . ' = ' . $this->db->quote('default'))
			->where($this->db->quoteName('key') . ' = ' . $this->db->quote('sd'));
		$existing = $this->db->setQuery($existsQuery)->loadAssoc();

		// Start from existing JSON (merge) or defaults (replace / no row).
		if ($mode === 'replace' || !$existing) {
			$current = $this->defaults();
		} else {
			$encoded = (string) ($existing['value'] ?? '');
			if ($encoded === '' && !empty($existing['large_value'])) {
				$encoded = (string) $existing['large_value'];
			}
			$decoded = json_decode($encoded, true);
			$current = is_array($decoded) ? array_merge($this->defaults(), $decoded) : $this->defaults();
		}

		$touched = [];

		// Scalar / mapped fields.
		$scalarMap = [
			'name'        => 'organizationName',
			'url'         => 'organizationUrl',
			'telephone'   => 'organizationTel',
			'price_range' => 'organizationPriceRange',
			'enabled'     => 'enabled',
			'enabled_local_business' => 'enabledLocalBusiness',
			'person_name' => 'personName',
			'person_url'  => 'personUrl',
			'google_maps_api_key' => 'googleMapsApiKey',
		];
		foreach ($scalarMap as $publicArg => $sdKey) {
			if (array_key_exists($publicArg, $arguments)) {
				$v = $arguments[$publicArg];
				$current[$sdKey] = is_bool($v) ? $v : (string) $v;
				$touched[] = $sdKey;
			}
		}

		// business_type → single-element array (renderer does array_pop).
		if (array_key_exists('business_type', $arguments)) {
			$bt = trim((string) $arguments['business_type']);
			$current['organizationType'] = $bt === '' ? [] : [$bt];
			$touched[] = 'organizationType';
		}

		// Address sub-object.
		if (array_key_exists('address', $arguments)) {
			$addr = $arguments['address'];
			if (!is_array($addr)) {
				return ToolResult::error('address must be an object.');
			}
			if (array_key_exists('streetAddress', $addr))   { $current['organizationStreetAddress']   = (string) $addr['streetAddress'];   $touched[] = 'organizationStreetAddress'; }
			if (array_key_exists('addressLocality', $addr)) { $current['organizationAddressLocality'] = (string) $addr['addressLocality']; $touched[] = 'organizationAddressLocality'; }
			if (array_key_exists('addressRegion', $addr))   { $current['organizationAddressRegion']   = (string) $addr['addressRegion'];   $touched[] = 'organizationAddressRegion'; }
			if (array_key_exists('postalCode', $addr))      { $current['organizationPostalCode']      = (string) $addr['postalCode'];      $touched[] = 'organizationPostalCode'; }
			if (array_key_exists('addressCountry', $addr)) {
				$cc = trim((string) $addr['addressCountry']);
				$current['organizationAddressCountry'] = $cc === '' ? [] : [$cc];
				$touched[] = 'organizationAddressCountry';
			}
		}

		// Geo.
		if (array_key_exists('latitude', $arguments)) {
			$current['organizationGeoLatitude'] = (string) $arguments['latitude'];
			$touched[] = 'organizationGeoLatitude';
		}
		if (array_key_exists('longitude', $arguments)) {
			$current['organizationGeoLongitude'] = (string) $arguments['longitude'];
			$touched[] = 'organizationGeoLongitude';
		}

		// Logo.
		if ($logoPatch !== null) {
			$current['organizationLogo'] = $logoPatch;
			$touched[] = 'organizationLogo';
		}

		// Hours: blanket-overwrite the 28-field grid + set type to CUSTOM.
		if ($hoursPatch !== null) {
			foreach ($hoursPatch as $k => $v) {
				$current[$k] = $v;
				$touched[] = $k;
			}
			$current['organizationHoursType'] = self::HOURS_TYPE_CUSTOM;
			$touched[] = 'organizationHoursType';
		}

		// Custom JSON-LD: accept object (stringified) or string (passed through).
		if (array_key_exists('custom_jsonld', $arguments)) {
			$cj = $arguments['custom_jsonld'];
			if (is_array($cj) || is_object($cj)) {
				$enc = json_encode($cj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				if ($enc === false) {
					return ToolResult::error('custom_jsonld failed to JSON-encode: ' . json_last_error_msg());
				}
				$current['organizationCustomCode'] = $enc;
			} else {
				$current['organizationCustomCode'] = (string) $cj;
			}
			$touched[] = 'organizationCustomCode';
		}

		// Encode + persist.
		$encoded = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return ToolResult::error('Failed to JSON-encode the resulting Business Profile: ' . json_last_error_msg());
		}

		$useLargeValue = strlen($encoded) > self::VALUE_COLUMN_MAX;
		$valueColVal      = $useLargeValue ? '' : $encoded;
		$largeValueColVal = $useLargeValue ? $encoded : '';

		$now = gmdate('Y-m-d H:i:s');

		if ($existing) {
			$update = $this->db->getQuery(true)
				->update($this->db->quoteName($fullTable))
				->set($this->db->quoteName('value') . ' = ' . $this->db->quote($valueColVal))
				->set($this->db->quoteName('large_value') . ' = ' . $this->db->quote($largeValueColVal))
				->set($this->db->quoteName('format') . ' = 2')
				->set($this->db->quoteName('modified_at') . ' = ' . $this->db->quote($now))
				->where($this->db->quoteName('id') . ' = ' . (int) $existing['id']);
			$this->db->setQuery($update)->execute();

			$action = 'updated';
			$rowId  = (int) $existing['id'];
		} else {
			$insert = $this->db->getQuery(true)
				->insert($this->db->quoteName($fullTable))
				->columns($this->db->quoteName(['scope', 'key', 'value', 'large_value', 'user_id', 'version', 'lock', 'lock_expires_at', 'format', 'modified_at']))
				->values(
					$this->db->quote('default') . ', '
					. $this->db->quote('sd') . ', '
					. $this->db->quote($valueColVal) . ', '
					. $this->db->quote($largeValueColVal) . ', '
					. (int) $actor->id . ', '
					. '0, '
					. $this->db->quote('') . ', '
					. 'NULL, '
					. '2, '
					. $this->db->quote($now)
				);
			$this->db->setQuery($insert)->execute();
			$action = 'inserted';
			$rowId  = (int) $this->db->insertid();
		}

		return ToolResult::json([
			'ok'             => true,
			'action'         => $action,
			'row_id'         => $rowId,
			'mode'           => $mode,
			'keys_touched'   => array_values(array_unique($touched)),
			'stored_in'      => $useLargeValue ? 'large_value' : 'value',
			'bytes'          => strlen($encoded),
			'business_type'  => $this->firstOrNull($current['organizationType'] ?? null),
			'verify_with'    => 'Call fetch_rendered_url on the site\'s home page and check that the #defaultBusiness JSON-LD node has a non-empty telephone, a resolving #defaultAddress with streetAddress, and a #defaultGeo with latitude+longitude. If any of those still look empty, re-check the relevant arg.',
		]);
	}

	/**
	 * Validate caller's opening_hours and emit the 28-field hoursDay{1,2}{Opens,Closes} patch.
	 */
	private function buildHoursPatch($input): array
	{
		if (!is_array($input)) {
			return [null, 'opening_hours must be an array of {day, opens, closes}.'];
		}

		// Initialise all 28 fields to empty — caller's array is the source of truth.
		$grid = [];
		foreach (array_keys(self::DAY_SHORTS) as $short) {
			$grid['hours' . $short . '1Opens']  = '';
			$grid['hours' . $short . '1Closes'] = '';
			$grid['hours' . $short . '2Opens']  = '';
			$grid['hours' . $short . '2Closes'] = '';
		}

		// Group entries by short-day so we can assign slot 1 vs slot 2.
		$byDay = [];
		foreach ($input as $i => $entry) {
			if (!is_array($entry)) {
				return [null, "opening_hours[$i] must be an object."];
			}
			$dayRaw = strtolower(trim((string) ($entry['day'] ?? '')));
			$short  = $this->dayShort($dayRaw);
			if ($short === null) {
				return [null, "opening_hours[$i].day must be a day name (Monday/Mon/monday). Got: '" . ($entry['day'] ?? '') . "'."];
			}
			$opens  = (string) ($entry['opens']  ?? '');
			$closes = (string) ($entry['closes'] ?? '');
			if (!preg_match('/^\d{2}:\d{2}$/', $opens) || !preg_match('/^\d{2}:\d{2}$/', $closes)) {
				return [null, "opening_hours[$i] opens/closes must be HH:MM 24-hour format. Got opens='$opens', closes='$closes'."];
			}
			$byDay[$short] = $byDay[$short] ?? [];
			$byDay[$short][] = ['opens' => $opens, 'closes' => $closes];
		}

		foreach ($byDay as $short => $slots) {
			if (count($slots) > 2) {
				return [null, "opening_hours has more than 2 slots for $short — 4SEO supports at most 2 per day."];
			}
			$grid['hours' . $short . '1Opens']  = $slots[0]['opens'];
			$grid['hours' . $short . '1Closes'] = $slots[0]['closes'];
			if (count($slots) === 2) {
				$grid['hours' . $short . '2Opens']  = $slots[1]['opens'];
				$grid['hours' . $short . '2Closes'] = $slots[1]['closes'];
			}
		}

		return [$grid, null];
	}

	private function dayShort(string $day): ?string
	{
		foreach (self::DAY_SHORTS as $short => $aliases) {
			if (in_array($day, $aliases, true) || strtolower($short) === $day) {
				return $short;
			}
		}
		return null;
	}

	/** Normalise logo to 4SEO's {url, width, height} object. */
	private function normaliseLogo($logo): array
	{
		if (is_string($logo)) {
			return [['url' => $logo, 'width' => '', 'height' => ''], null];
		}
		if (is_array($logo)) {
			$url = (string) ($logo['url'] ?? '');
			if ($url === '') {
				return [null, 'logo object requires a url property.'];
			}
			return [[
				'url'    => $url,
				'width'  => isset($logo['width'])  ? (string) $logo['width']  : '',
				'height' => isset($logo['height']) ? (string) $logo['height'] : '',
			], null];
		}
		return [null, 'logo must be a URL string or {url, width, height} object.'];
	}

	private function firstOrNull($v): ?string
	{
		if (!is_array($v) || $v === []) {
			return null;
		}
		return (string) reset($v);
	}
}
