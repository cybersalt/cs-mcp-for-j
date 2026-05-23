<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj4seo\Tools\BusinessProfile;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\AbstractTool;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolResult;
use Joomla\CMS\User\User;

/**
 * Read 4SEO's Business Profile config — the site-wide LocalBusiness data that
 * 4SEO emits as the #defaultBusiness JSON-LD node on every page.
 *
 * Storage: #__forseo_config row (scope='default', key='sd'). The value column
 * holds a JSON-encoded array of the keys defined in 4SEO's config/sd.php. This
 * tool decodes that array and surfaces the Business-Profile-relevant subset in
 * a flattened, agent-friendly shape (address as a nested object, opening_hours
 * as a normalised array of slots, etc.) without exposing the agent to 4SEO's
 * 28-field hours grid.
 *
 * If the (default,sd) row does NOT exist yet (i.e. the human has never saved
 * the Business Profile form), this tool returns `configured: false` and the
 * empty-skeleton values — same observable state as fetching the home page
 * would show. set_4seo_business_profile can create the row from scratch.
 */
final class GetBusinessProfileTool extends AbstractTool
{
	public function getName(): string { return 'get_4seo_business_profile'; }

	public function getDescription(): string
	{
		return 'Read 4SEO\'s Business Profile / site-wide LocalBusiness config. Returns '
			. 'business_type, name, url, telephone, address (nested), latitude, longitude, '
			. 'opening_hours (normalised), logo, price_range, and the configured flags. If '
			. 'the form has never been saved on this site the tool returns configured:false '
			. 'with empty values — call set_4seo_business_profile to create the row. The '
			. 'returned shape matches what set_4seo_business_profile accepts as input.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	public function getRequiredPermission(): string { return 'use'; }

	protected function run(array $arguments, User $actor): ToolResult
	{
		$fullTable = $this->db->getPrefix() . 'forseo_config';

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName(['id', 'value', 'large_value', 'format', 'modified_at']))
			->from($this->db->quoteName($fullTable))
			->where($this->db->quoteName('scope') . ' = ' . $this->db->quote('default'))
			->where($this->db->quoteName('key') . ' = ' . $this->db->quote('sd'));
		$row = $this->db->setQuery($query)->loadAssoc();

		if (!$row) {
			return ToolResult::json([
				'ok'         => true,
				'configured' => false,
				'note'       => 'No #__forseo_config row exists yet for (scope=default, key=sd). 4SEO is rendering the empty #defaultBusiness skeleton. Use set_4seo_business_profile to create the row.',
			]);
		}

		$encoded = (string) ($row['value'] ?? '');
		if ($encoded === '' && !empty($row['large_value'])) {
			$encoded = (string) $row['large_value'];
		}
		$sd = json_decode($encoded, true);
		$sd = is_array($sd) ? $sd : [];

		return ToolResult::json([
			'ok'              => true,
			'configured'      => true,
			'row_id'          => (int) $row['id'],
			'modified_at'     => (string) ($row['modified_at'] ?? ''),
			'business_type'   => $this->firstOrNull($sd['organizationType'] ?? null),
			'name'            => (string) ($sd['organizationName'] ?? ''),
			'url'             => (string) ($sd['organizationUrl'] ?? ''),
			'telephone'       => (string) ($sd['organizationTel'] ?? ''),
			'price_range'     => (string) ($sd['organizationPriceRange'] ?? ''),
			'address'         => [
				'streetAddress'   => (string) ($sd['organizationStreetAddress'] ?? ''),
				'addressLocality' => (string) ($sd['organizationAddressLocality'] ?? ''),
				'addressRegion'   => (string) ($sd['organizationAddressRegion'] ?? ''),
				'postalCode'      => (string) ($sd['organizationPostalCode'] ?? ''),
				'addressCountry'  => $this->firstOrNull($sd['organizationAddressCountry'] ?? null),
			],
			'latitude'        => (string) ($sd['organizationGeoLatitude'] ?? ''),
			'longitude'       => (string) ($sd['organizationGeoLongitude'] ?? ''),
			'logo'            => is_array($sd['organizationLogo'] ?? null) ? $sd['organizationLogo'] : [],
			'opening_hours'   => $this->extractHours($sd),
			'hours_type'      => (int) ($sd['organizationHoursType'] ?? 0),
			'enabled'         => (bool) ($sd['enabled'] ?? true),
			'enabled_local_business' => (bool) ($sd['enabledLocalBusiness'] ?? true),
			'custom_jsonld'   => (string) ($sd['organizationCustomCode'] ?? ''),
			'google_maps_api_key' => (string) ($sd['googleMapsApiKey'] ?? ''),
			'person_name'     => (string) ($sd['personName'] ?? ''),
			'person_url'      => (string) ($sd['personUrl'] ?? ''),
			'raw_keys_present' => array_keys($sd),
		]);
	}

	private function firstOrNull($v): ?string
	{
		if (!is_array($v) || $v === []) {
			return null;
		}
		$first = reset($v);
		return $first === null ? null : (string) $first;
	}

	/**
	 * Flatten 4SEO's hoursMon1Opens / hoursMon1Closes / hoursMon2Opens /
	 * hoursMon2Closes grid into a tidy [{day, opens, closes}] array.
	 */
	private function extractHours(array $sd): array
	{
		$days = [
			'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday',
			'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday',
		];
		$out = [];
		foreach ($days as $short => $long) {
			foreach (['1', '2'] as $slot) {
				$opens  = (string) ($sd['hours' . $short . $slot . 'Opens'] ?? '');
				$closes = (string) ($sd['hours' . $short . $slot . 'Closes'] ?? '');
				if ($opens !== '' && $closes !== '') {
					$out[] = ['day' => $long, 'opens' => $opens, 'closes' => $closes];
				}
			}
		}
		return $out;
	}
}
