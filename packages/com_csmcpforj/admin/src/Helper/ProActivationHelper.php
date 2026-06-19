<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Pro Activation Helper.
 *
 * Glue code that talks to cs-release-manager's two-step activation API
 * (register + linkemail) on cybersalt.com and stores the resulting dlid
 * (download id) in the component params so install_extension and Joomla's
 * Find Updates can pass it on every Pro download.
 *
 * Flow per the cs-release-manager design:
 *
 *   1. registerInstallation()
 *      POST /api/index.php/v1/csreleasemanager/register
 *      body: { installation_id, package_element, domain }
 *      Creates the #__csrm_installations row in 'unlinked' status.
 *
 *   2. linkEmail(email)
 *      POST /index.php?option=com_csreleasemanager&task=api.linkemail
 *      body: { installation_id, email_hash, email }
 *      cs-release-manager hashes the email, looks up the matching Joomla
 *      user on cybersalt.com, checks user_groups membership against the
 *      Package's allowed groups, and either links (status='active') or
 *      refuses (403 with a friendly denial reason).
 *
 *   3. From then on, getDlid() returns "installation_id:email_hash" which
 *      callers append to download URLs as &dlid=... to satisfy
 *      cs-release-manager's AccessCheckHelper::verifyUpdateAccess gate.
 */
final class ProActivationHelper
{
	private const RELEASE_MANAGER_BASE = 'https://www.cybersalt.com';

	// Both endpoints use cs-release-manager's site-router style — the
	// /api/index.php/v1/csreleasemanager/register variant exists in the
	// codebase but is NOT registered in the webservices plugin's route table,
	// so hitting it returns 406 (Not Acceptable) from Joomla's API router
	// before reaching the controller. The index.php?task=api.X form goes
	// through the same ApiController and works without any plugin route
	// declaration.
	private const REGISTER_URL     = self::RELEASE_MANAGER_BASE . '/index.php?option=com_csreleasemanager&task=api.register&format=json';
	private const LINKEMAIL_URL    = self::RELEASE_MANAGER_BASE . '/index.php?option=com_csreleasemanager&task=api.linkemail&format=json';
	// Used by verifyAccess() — the new JSON endpoint introduced in cs-release-manager
	// v1.9.0 that returns the 4-state {active|lapsed|not_a_member|blacklisted}
	// instead of the XML checkupdate response. This replaces the old
	// verifyAccessAfterLink() path that had to parse XML and false-failed when
	// a granted Package had download_url empty.
	private const VERIFYACCESS_URL = self::RELEASE_MANAGER_BASE . '/index.php?option=com_csreleasemanager&task=api.verifyaccess&format=json';
	private const HTTP_TIMEOUT     = 15;

	// cs-release-manager pins each installation_id to ONE Package (verified at
	// AccessCheckHelper::verifyUpdateAccess time via installation->package_id),
	// so the activation has to register against a real Pro Package — not the
	// Free `pkg_csmcpforj` core — for the user_groups gate to actually fire.
	// We anchor against the 4SEO add-on Package because it's the first Pro
	// Package in the catalog; any Pro Package would work since they all share
	// the same Cybersalt membership user_groups.
	//
	// FUTURE: when we sell membership tiers with different user_groups, this
	// needs to become per-add-on activation (or cs-release-manager needs a
	// "verify any Pro" endpoint independent of a specific Package).
	// Activation anchors against a dedicated "MCP for J Pro Membership" Package
	// on cs-release-manager (extension_element=csmcpforjpro). It's a virtual
	// SKU — there's no real Joomla extension with this element, but the
	// Package row exists so register / linkemail / verifyaccess have a Package
	// to gate against, AND so the "new install" notification email Tim gets
	// reads "MCP for J Pro Membership" instead of "MCP add-on for 4SEO".
	// Previous anchors (csmcpforj4seo and pkg_csmcpforj) muddled the user
	// experience: 4SEO is just one of several add-ons, and pkg_csmcpforj is the
	// free core component (can't be Pro-gated without breaking free installs).
	private const ACTIVATION_ANCHOR_ELEMENT = 'csmcpforjpro';

	/**
	 * Per-request memo of the installation_id. Without this, ensureInstallationId()
	 * could return TWO DIFFERENT ids inside one activatePro request: the register
	 * call generates+saves id "B", linkEmail re-enters ensureInstallationId, but
	 * ComponentHelper has cached an EARLIER copy of the params blob (taken at
	 * request bootstrap, before our saveParam wrote "B"), reads pro_installation_id
	 * as '', generates a fresh "C", saves it, and POSTs that to linkemail. The
	 * cs-release-manager row for "B" exists but no row for "C" — linkemail returns
	 * 404 "Installation not found". Surfaced as Tim's "I just deactivated and
	 * re-activated and got Installation not found" bug.
	 */
	private static ?string $memoInstallationId = null;

	/**
	 * Read the cs-mcp-for-j pro_* params DIRECTLY from #__extensions, bypassing
	 * ComponentHelper's static cache. Found in production on cybersalt.com
	 * 2026-06-13: ComponentHelper's cached params blob got out of sync with
	 * the DB even after saveParam's reflection-based cache bust — the dashboard
	 * always saw stale empty values for pro_status / pro_email even though
	 * the saves had committed. Bypassing the cache resolves it.
	 *
	 * @return array{installation_id:string,email:string,email_hash:string,status:string,
	 *               renewal_url:string,signup_url:string,message:string,package_title:string,
	 *               last_verified:string,recheck_seconds:string}
	 */
	public static function readPro(): array
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
		$q  = $db->getQuery(true)
			->select($db->quoteName('params'))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_csmcpforj'));
		$raw = (string) ($db->setQuery($q)->loadResult() ?? '');
		$data = json_decode($raw, true) ?: [];

		return [
			'installation_id' => (string) ($data['pro_installation_id'] ?? ''),
			'email'           => (string) ($data['pro_email'] ?? ''),
			'email_hash'      => (string) ($data['pro_email_hash'] ?? ''),
			'status'          => (string) ($data['pro_status'] ?? ''),
			'renewal_url'     => (string) ($data['pro_renewal_url'] ?? ''),
			'signup_url'      => (string) ($data['pro_signup_url'] ?? ''),
			'message'         => (string) ($data['pro_message'] ?? ''),
			'package_title'   => (string) ($data['pro_package_title'] ?? ''),
			'last_verified'   => (string) ($data['pro_last_verified'] ?? ''),
			'recheck_seconds' => (string) ($data['pro_recheck_seconds'] ?? '86400'),
		];
	}

	/**
	 * Returns the Pro Package element the activation flow anchors against.
	 * Exposed so the dashboard controller doesn't duplicate the constant.
	 */
	public static function getActivationAnchorElement(): string
	{
		return self::ACTIVATION_ANCHOR_ELEMENT;
	}

	/**
	 * Generates an installation_id in cs-release-manager's expected format:
	 * "YmdHis_domain_xxxxxx" (timestamp + lowercase host + 6 hex chars).
	 *
	 * Stable per site — once generated and saved, never regenerated. cs-release-manager's
	 * installation_id is the UNIQUE key in #__csrm_installations; calling
	 * register a second time with a new id would create a duplicate "unlinked"
	 * row that's hard to reconcile.
	 *
	 * The static $memoInstallationId guarantees that within a single PHP request
	 * we hand back ONE id, regardless of ComponentHelper's param-cache state.
	 * See the property docblock for why this matters.
	 */
	public static function ensureInstallationId(): string
	{
		if (self::$memoInstallationId !== null && self::$memoInstallationId !== '') {
			return self::$memoInstallationId;
		}

		// Direct-DB read so we don't get ComponentHelper's stale cache.
		$installationId = self::readPro()['installation_id'];

		if ($installationId !== '') {
			self::$memoInstallationId = $installationId;
			return $installationId;
		}

		$timestamp = date('YmdHis');
		$domain    = strtolower((string) (parse_url((string) Uri::root(), PHP_URL_HOST) ?: 'localhost'));
		$random    = bin2hex(random_bytes(3));

		$installationId = $timestamp . '_' . $domain . '_' . $random;

		self::saveParam('pro_installation_id', $installationId);
		self::$memoInstallationId = $installationId;
		return $installationId;
	}

	/**
	 * Returns true when both halves of a usable dlid are present (installation
	 * has been registered AND linked to an email that passed cs-release-manager's
	 * user_groups gate).
	 */
	public static function isActivated(): bool
	{
		$pro = self::readPro();
		return $pro['installation_id'] !== ''
			&& $pro['email_hash'] !== ''
			&& $pro['status'] === 'active';
	}

	/**
	 * Returns the dlid string for appending to download URLs as &dlid=...
	 * Format: "installation_id:email_hash" — matches the api.download endpoint's
	 * parse_user_dlid() helper.
	 */
	public static function getDlid(): string
	{
		$pro = self::readPro();
		if ($pro['installation_id'] === '' || $pro['email_hash'] === '') {
			return '';
		}
		return $pro['installation_id'] . ':' . $pro['email_hash'];
	}

	/**
	 * Step 1 of activation: register the installation with cs-release-manager
	 * against a specific Package element. Idempotent — cs-release-manager
	 * returns 'already_registered' if the installation_id has been seen.
	 *
	 * @param string $packageElement e.g. "pkg_csmcpforj4seo" — must match a
	 *                                Package row's extension_element on cybersalt.com.
	 * @return array{ok: bool, status: string, error: string|null, http_code: int}
	 */
	public static function registerInstallation(string $packageElement): array
	{
		$installationId = self::ensureInstallationId();
		$domain         = (string) (parse_url((string) Uri::root(), PHP_URL_HOST) ?: '');

		$body = json_encode([
			'installation_id' => $installationId,
			'package_element' => $packageElement,
			'domain'          => $domain,
		], JSON_UNESCAPED_SLASHES);

		try {
			$response = HttpFactory::getHttp()->post(
				self::REGISTER_URL,
				$body,
				['Content-Type' => 'application/json', 'Accept' => 'application/json'],
				self::HTTP_TIMEOUT
			);
		} catch (\Throwable $e) {
			return ['ok' => false, 'status' => '', 'error' => 'Network error: ' . $e->getMessage(), 'http_code' => 0];
		}

		$code    = (int) $response->code;
		$payload = json_decode((string) $response->body, true);
		if (!is_array($payload)) {
			$payload = [];
		}

		if ($code === 201 || $code === 200) {
			return [
				'ok'        => true,
				'status'    => (string) ($payload['status'] ?? ''),
				'error'     => null,
				'http_code' => $code,
			];
		}

		return [
			'ok'        => false,
			'status'    => (string) ($payload['status'] ?? ''),
			'error'     => (string) ($payload['error'] ?? 'HTTP ' . $code),
			'http_code' => $code,
		];
	}

	/**
	 * Step 2 of activation: bind the installation to a customer email via
	 * cs-release-manager's api.linkemail endpoint. This is where the
	 * user_groups gating actually happens — cs-release-manager looks up the
	 * Joomla user with this email on cybersalt.com and checks group membership.
	 *
	 * On success, persists pro_email + pro_email_hash + pro_status='active' in
	 * the component params. From this point getDlid() returns a usable string.
	 *
	 * @return array{ok: bool, error: string|null, denial_reason: string|null,
	 *               renewal_url: string|null, http_code: int}
	 */
	public static function linkEmail(string $email): array
	{
		$email          = strtolower(trim($email));
		$installationId = self::ensureInstallationId();
		$emailHash      = hash('sha256', $email);

		$body = json_encode([
			'installation_id' => $installationId,
			'email_hash'      => $emailHash,
			'email'           => $email,
		], JSON_UNESCAPED_SLASHES);

		try {
			$response = HttpFactory::getHttp()->post(
				self::LINKEMAIL_URL,
				$body,
				['Content-Type' => 'application/json', 'Accept' => 'application/json'],
				self::HTTP_TIMEOUT
			);
		} catch (\Throwable $e) {
			return [
				'ok'            => false,
				'error'         => 'Network error: ' . $e->getMessage(),
				'denial_reason' => null,
				'renewal_url'   => null,
				'http_code'     => 0,
			];
		}

		$code    = (int) $response->code;
		$payload = json_decode((string) $response->body, true);
		if (!is_array($payload)) {
			$payload = [];
		}

		if ($code !== 200 && $code !== 201) {
			// linkemail itself failed — blacklist hit, install limit, etc.
			$renewal = self::extractRenewalUrl((string) ($payload['error'] ?? ''));
			if ($renewal !== '') {
				self::saveParam('pro_renewal_url', $renewal);
			}
			self::saveParam('pro_status', 'denied');
			return [
				'ok'            => false,
				'error'         => (string) ($payload['error'] ?? 'HTTP ' . $code),
				'denial_reason' => (string) ($payload['error'] ?? ''),
				'renewal_url'   => $renewal,
				'http_code'     => $code,
			];
		}

		// linkemail succeeded — now ask cs-release-manager's verifyaccess
		// endpoint for the FULL 4-state membership picture (active / lapsed /
		// not_a_member / blacklisted). The endpoint also distinguishes
		// terminal error states (installation_not_found, etc) that we map to
		// 'denied' so the dashboard never needs to know about them.
		$verifyResult = self::verifyAccess($emailHash);

		// Persist the email + email_hash on the local install regardless of
		// state — the dashboard surfaces them in every non-active state so the
		// user can SEE what they tried (and which signup/renewal URL applies).
		// They get cleared only via Deactivate, not on a denial bounce.
		self::saveParam('pro_email', $email);
		self::saveParam('pro_email_hash', $emailHash);
		self::saveParam('pro_status', $verifyResult['state']);
		self::saveParam('pro_renewal_url', (string) $verifyResult['renewal_url']);
		self::saveParam('pro_signup_url', (string) $verifyResult['signup_url']);
		self::saveParam('pro_message', (string) $verifyResult['message']);
		self::saveParam('pro_package_title', (string) $verifyResult['package_title']);
		// Timestamp the verification so refreshIfStale() can throttle subsequent
		// re-checks. Without this, a freshly-activated install would re-verify
		// on the very next dashboard load.
		self::saveParam('pro_last_verified', (string) time());

		return [
			'ok'            => $verifyResult['state'] === 'active',
			'state'         => $verifyResult['state'],
			'message'       => $verifyResult['message'],
			'renewal_url'   => $verifyResult['renewal_url'],
			'signup_url'    => $verifyResult['signup_url'],
			'package_title' => $verifyResult['package_title'],
			'http_code'     => $code,
		];
	}

	/**
	 * Calls cs-release-manager's api.verifyaccess JSON endpoint (introduced
	 * in cs-release-manager v1.9.0) to get the FULL 4-state membership
	 * picture for the current installation_id + email_hash. Replaces the
	 * older verifyAccessAfterLink() that parsed checkupdate XML and false-
	 * failed when a Package's download_url was blank.
	 *
	 * Why this is separate from linkEmail(): cs-release-manager's linkemail
	 * endpoint is "create local record"; the actual gate (does this email's
	 * Joomla user exist + is it in the allowed user_groups?) fires only here.
	 * Without this round-trip, any email passed through linkemail would
	 * appear locally as "Active".
	 *
	 * Maps server-side state codes to the values the dashboard understands:
	 *   active / lapsed / not_a_member / blacklisted    → returned verbatim
	 *   installation_not_found / package_not_found /
	 *   email_mismatch / unlinked / network errors      → collapsed to 'denied'
	 *
	 * @return array{state: string, message: string, renewal_url: string,
	 *               signup_url: string, package_title: string}
	 */
	private static function verifyAccess(string $emailHash): array
	{
		$installationId = self::ensureInstallationId();
		$dlid           = $installationId . ':' . $emailHash;
		$url            = self::VERIFYACCESS_URL . '&dlid=' . rawurlencode($dlid);

		try {
			$response = HttpFactory::getHttp()->get($url, [], self::HTTP_TIMEOUT);
		} catch (\Throwable $e) {
			return [
				'state'         => 'denied',
				'message'       => 'Verification network error: ' . $e->getMessage(),
				'renewal_url'   => '',
				'signup_url'    => '',
				'package_title' => '',
			];
		}

		$payload = json_decode((string) $response->body, true);
		if (!is_array($payload) || empty($payload['state'])) {
			return [
				'state'         => 'denied',
				'message'       => 'Verification returned an unexpected response (HTTP ' . (int) $response->code . ').',
				'renewal_url'   => '',
				'signup_url'    => '',
				'package_title' => '',
			];
		}

		// State allow-list — anything outside this collapses to 'denied' so the
		// dashboard never has to know about server-side terminal error codes.
		$state = (string) $payload['state'];
		if (!in_array($state, ['active', 'lapsed', 'not_a_member', 'blacklisted'], true)) {
			$state = 'denied';
		}

		return [
			'state'         => $state,
			'message'       => (string) ($payload['message'] ?? ''),
			'renewal_url'   => (string) ($payload['renewal_url'] ?? ''),
			'signup_url'    => (string) ($payload['signup_url'] ?? ''),
			'package_title' => (string) ($payload['package_title'] ?? ''),
		];
	}

	/**
	 * Re-verify the locally-stored Pro state with cs-release-manager if the
	 * cached state is older than the recheck interval. Called from the
	 * dashboard view's display() so a membership change on cybersalt.com
	 * (e.g. an admin moves a user from "MCP for J" to "MCP for J Renewable")
	 * is reflected in the dashboard within one interval.
	 *
	 * Throttled by the `pro_recheck_seconds` component param:
	 *   - default 86400 (once a day) for production
	 *   - 0 means recheck on every call (good for testing)
	 *   - any other positive integer = throttle to that many seconds
	 *
	 * Silent on failure — if the verifyaccess HTTP call fails for any reason,
	 * the previously-stored state stays in place. We never lock the user out
	 * of Pro features over a transient network blip.
	 *
	 * Idempotent within a single PHP request: the static $rechecked flag
	 * ensures multiple dashboard renders in one request only check once.
	 */
	/**
	 * Force an immediate re-verify against cs-release-manager, bypassing the
	 * recheck_seconds TTL. Used by the dashboard's "Refresh Membership Status"
	 * button after a user renews their membership on cybersalt.com so they
	 * don't have to wait for the TTL to expire before seeing the new state.
	 *
	 * Returns the new state ('active' / 'lapsed' / 'not_a_member' /
	 * 'blacklisted' / 'denied') for the controller to surface in a flash
	 * message. Returns '' if there's no installation_id or email_hash to
	 * verify (caller should treat as "nothing to refresh").
	 */
	public static function forceRefresh(): string
	{
		$pro = self::readPro();
		if ($pro['installation_id'] === '' || $pro['email_hash'] === '') {
			return '';
		}

		$result = self::verifyAccess($pro['email_hash']);

		self::saveParam('pro_renewal_url', (string) $result['renewal_url']);
		self::saveParam('pro_signup_url', (string) $result['signup_url']);
		self::saveParam('pro_message', (string) $result['message']);
		self::saveParam('pro_package_title', (string) $result['package_title']);
		self::saveParam('pro_status', (string) $result['state']);
		self::saveParam('pro_last_verified', (string) time());

		return (string) $result['state'];
	}

	public static function refreshIfStale(): void
	{
		static $rechecked = false;
		if ($rechecked) {
			return;
		}
		$rechecked = true;

		// Direct-DB read so we see committed values, not ComponentHelper's
		// stale static cache (see readPro() docblock for the production bug
		// this works around).
		$pro = self::readPro();

		// No installation_id or no email_hash → nothing to recheck.
		if ($pro['installation_id'] === '' || $pro['email_hash'] === '') {
			return;
		}

		$throttleSeconds = (int) $pro['recheck_seconds'];
		$lastVerified    = (int) $pro['last_verified'];
		$emailHash       = $pro['email_hash'];
		$now             = time();

		// Throttle window — only recheck if enough time has passed. Throttle=0
		// means "recheck every time" (testing mode).
		if ($throttleSeconds > 0 && $lastVerified > 0 && ($now - $lastVerified) < $throttleSeconds) {
			return;
		}

		$result = self::verifyAccess($emailHash);

		// Persist updated state. Order matters: write fields first, then the
		// status, so a half-finished write leaves the dashboard slightly out of
		// date instead of in a fully inconsistent state.
		self::saveParam('pro_renewal_url', (string) $result['renewal_url']);
		self::saveParam('pro_signup_url', (string) $result['signup_url']);
		self::saveParam('pro_message', (string) $result['message']);
		self::saveParam('pro_package_title', (string) $result['package_title']);
		self::saveParam('pro_status', (string) $result['state']);
		self::saveParam('pro_last_verified', (string) $now);
	}

	/**
	 * Forget the local Pro state. Does NOT call cs-release-manager — the
	 * installation row stays in cybersalt.com's database. To revoke server-side
	 * the operator removes the row via the customer portal on cybersalt.com.
	 *
	 * Also clears the installation_id so the NEXT activate generates a fresh
	 * one. cs-release-manager pins each installation_id to one Package
	 * (installation->package_id), and we can't change that anchor on a
	 * second register call (the endpoint returns 'already_registered' and
	 * keeps the original package_id). So if the previous activation anchored
	 * against the wrong Package (e.g. an early version that used the Free
	 * pkg_csmcpforj instead of a Pro Package), the only way to re-anchor is
	 * a fresh installation_id.
	 *
	 * Side effect: any cybersalt.com row pointing at the old installation_id
	 * becomes orphaned. That's fine — installations are cheap and the
	 * customer portal can prune them.
	 */
	public static function deactivate(): void
	{
		self::saveParam('pro_installation_id', '');
		self::saveParam('pro_email', '');
		self::saveParam('pro_email_hash', '');
		self::saveParam('pro_status', '');
		self::saveParam('pro_renewal_url', '');
		self::saveParam('pro_signup_url', '');
		self::saveParam('pro_message', '');
		self::saveParam('pro_package_title', '');
		self::saveParam('pro_last_verified', '');

		// Clear the per-request memo so the very next call to ensureInstallationId()
		// (e.g. if Tim deactivates then re-activates in the same browser session
		// hitting separate task=dashboard.deactivatePro / dashboard.activatePro
		// requests in fast succession) generates a fresh id rather than re-using
		// the cleared one from a still-warm process pool.
		self::$memoInstallationId = null;
	}

	/**
	 * Persist a key into com_csmcpforj's params blob. Uses the component
	 * extension row directly because the standard ComponentHelper::getParams
	 * is read-only and we want this to survive across Joomla cache busts.
	 */
	private static function saveParam(string $key, string $value): void
	{
		$db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);

		$q = $db->getQuery(true)
			->select($db->quoteName(['extension_id', 'params']))
			->from($db->quoteName('#__extensions'))
			->where($db->quoteName('type') . ' = ' . $db->quote('component'))
			->where($db->quoteName('element') . ' = ' . $db->quote('com_csmcpforj'));

		$row = $db->setQuery($q)->loadAssoc();
		if (!$row) {
			return;
		}

		$params = new Registry((string) ($row['params'] ?? ''));
		$params->set($key, $value);

		$update = $db->getQuery(true)
			->update($db->quoteName('#__extensions'))
			->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
			->where($db->quoteName('extension_id') . ' = ' . (int) $row['extension_id']);
		$db->setQuery($update)->execute();

		// Bust ComponentHelper's in-process cache so subsequent ::getParams()
		// calls in the same request see the new value.
		$reflectionClass = new \ReflectionClass(ComponentHelper::class);
		if ($reflectionClass->hasProperty('components')) {
			$prop = $reflectionClass->getProperty('components');
			$prop->setAccessible(true);
			$components = $prop->getValue();
			if (is_array($components) && isset($components['com_csmcpforj'])) {
				unset($components['com_csmcpforj']);
				$prop->setValue(null, $components);
			}
		}
	}

	/**
	 * Pull the first http(s):// URL out of a denial-error message, so the
	 * dashboard can render it as a "Renew membership" link.
	 */
	private static function extractRenewalUrl(string $message): string
	{
		if (preg_match('#https?://\S+#i', $message, $matches)) {
			return rtrim($matches[0], '. ,;)');
		}
		return '';
	}
}
