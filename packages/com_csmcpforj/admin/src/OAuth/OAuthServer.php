<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\OAuth;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Utilities\IpHelper;

/**
 * OAuth 2.1 authorization for the MCP endpoint (GitHub #36).
 *
 * Makes this Joomla site both the authorization server and the protected
 * resource for its own MCP endpoint, so clients whose "add connector" dialog
 * only speaks OAuth (ChatGPT custom connectors, claude.ai / Claude Desktop
 * "Add custom connector") can connect. It runs ALONGSIDE Joomla API tokens,
 * which keep working exactly as before.
 *
 * What is implemented, per the MCP authorization spec (2025-06-18):
 *   - Protected Resource Metadata (RFC 9728) and the 401 WWW-Authenticate
 *     challenge that points at it.
 *   - Authorization Server Metadata (RFC 8414), also answered at
 *     /.well-known/openid-configuration for clients that only look there.
 *   - Dynamic Client Registration (RFC 7591). Public clients by default;
 *     clients that ask for client_secret_post / client_secret_basic get a
 *     secret.
 *   - Authorization code with PKCE, S256 only. The authorize step uses the
 *     site's own Joomla login (so its MFA applies) and then a consent screen
 *     naming the app and the Joomla user it will act as.
 *   - Resource Indicators (RFC 8707): tokens are bound to this site's MCP
 *     endpoint and are only accepted there.
 *   - Rotating refresh tokens with reuse detection, and revocation (RFC 7009).
 *
 * Tokens are opaque random strings. Only their SHA-256 hashes are stored.
 * An access token stops working at once when it expires, is revoked, its app
 * is revoked, OAuth is switched off, or its user is blocked, deleted, or
 * changes password.
 *
 * Written from scratch for this project (GPL-2.0-or-later).
 */
final class OAuthServer
{
	public const ACCESS_PREFIX  = 'mcpj_at_';
	public const REFRESH_PREFIX = 'mcpj_rt_';

	/** Query-string key that routes a site request to an OAuth endpoint. */
	public const QUERY_KEY = 'csmcpforj_oauth';

	public const SCOPE = 'mcp';

	private const CODE_TTL_SECONDS       = 600;
	private const REGISTRATIONS_PER_HOUR = 20;
	private const MAX_REDIRECT_URIS      = 10;

	private const T_CLIENTS = '#__csmcpforj_oauth_clients';
	private const T_CODES   = '#__csmcpforj_oauth_codes';
	private const T_TOKENS  = '#__csmcpforj_oauth_tokens';

	/** @var array<string, array|null> per-request validation cache */
	private static array $validated = [];

	/** @var array<string, string> why a token failed validation, by token hash */
	private static array $rejections = [];

	/** Activity-log context for the current OAuth request (app, user). */
	private static array $logCtx = [];

	/** Why the last authenticateClient() call returned null. */
	private static string $clientFailure = '';

	// ------------------------------------------------------------------
	// Configuration and URLs
	// ------------------------------------------------------------------

	public static function isEnabled(): bool
	{
		return (bool) ComponentHelper::getParams('com_csmcpforj')->get('oauth_enabled', 0);
	}

	private static function accessTtl(): int
	{
		$ttl = (int) ComponentHelper::getParams('com_csmcpforj')->get('oauth_access_ttl', 3600);

		return max(300, min($ttl, 86400));
	}

	private static function refreshTtl(): int
	{
		$days = (int) ComponentHelper::getParams('com_csmcpforj')->get('oauth_refresh_days', 30);

		return max(1, min($days, 365)) * 86400;
	}

	/** Site base URL with no trailing slash. Used as the OAuth issuer. */
	public static function issuer(): string
	{
		return rtrim(Uri::root(), '/');
	}

	/** The protected resource: this site's MCP endpoint. */
	public static function resourceUrl(): string
	{
		return self::issuer() . '/api/index.php/v1/mcp';
	}

	public static function endpoint(string $name): string
	{
		return self::issuer() . '/index.php?' . self::QUERY_KEY . '=' . rawurlencode($name);
	}

	/**
	 * Where the 401 challenge points clients for the resource metadata.
	 *
	 * The well-known path only reaches Joomla when URL rewriting is on (the
	 * .htaccess sends unknown paths to index.php). Without rewriting, point at
	 * an index.php URL Joomla always receives. RFC 9728 allows any URL here.
	 */
	public static function resourceMetadataUrl(): string
	{
		if ((int) Factory::getApplication()->get('sef_rewrite', 0) === 1) {
			return self::issuer() . '/.well-known/oauth-protected-resource';
		}

		return self::endpoint('resource-metadata');
	}

	public static function challengeHeader(string $error = '', string $description = ''): string
	{
		$header = 'Bearer realm="mcp", resource_metadata="' . self::resourceMetadataUrl() . '"';

		if ($error !== '') {
			$header .= ', error="' . $error . '"';

			if ($description !== '') {
				$header .= ', error_description="' . str_replace('"', "'", $description) . '"';
			}
		}

		return $header;
	}

	public static function protectedResourceMetadata(): array
	{
		return [
			'resource'                 => self::resourceUrl(),
			'authorization_servers'    => [self::issuer()],
			'scopes_supported'         => [self::SCOPE],
			'bearer_methods_supported' => ['header'],
			'resource_name'            => (string) Factory::getApplication()->get('sitename', 'Joomla') . ' (MCP for Joomla)',
		];
	}

	public static function authorizationServerMetadata(): array
	{
		return [
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint('authorize'),
			'token_endpoint'                        => self::endpoint('token'),
			'registration_endpoint'                 => self::endpoint('register'),
			'revocation_endpoint'                   => self::endpoint('revoke'),
			'response_types_supported'              => ['code'],
			'response_modes_supported'              => ['query'],
			'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported'      => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
			'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
			'scopes_supported'                      => [self::SCOPE],
			'authorization_response_iss_parameter_supported' => true,
		];
	}

	// ------------------------------------------------------------------
	// Request routing (site application, from the system plugin)
	// ------------------------------------------------------------------

	/**
	 * Cheap test, run on every site request: is this one of ours?
	 * Returns the endpoint name, or null.
	 */
	public static function matchRequest(string $path, ?string $queryValue): ?string
	{
		if ($queryValue !== null && $queryValue !== '') {
			$name = strtolower($queryValue);

			return in_array($name, ['authorize', 'token', 'register', 'revoke', 'metadata', 'resource-metadata'], true)
				? $name : null;
		}

		$pos = strpos($path, '/.well-known/');

		if ($pos === false) {
			return null;
		}

		$tail = substr($path, $pos + strlen('/.well-known/'));

		// Path-suffixed forms (RFC 8414 §3, RFC 9728 §3.1) included.
		if (preg_match('#^oauth-protected-resource(/|$)#', $tail)) {
			return 'resource-metadata';
		}

		if (preg_match('#^(oauth-authorization-server|openid-configuration)(/|$)#', $tail)) {
			return 'metadata';
		}

		return null;
	}

	public static function handle(string $route, CMSApplication $app): void
	{
		try {
			switch ($route) {
				case 'resource-metadata':
					self::log('info', 'oauth.discovery', 'Sent the protected-resource metadata (step 1 of an app connecting).');
					self::sendJson(self::protectedResourceMetadata(), 200, true);
					break;
				case 'metadata':
					// The Dashboard's "Ready" check fetches this on every visit.
					if (!isset($_GET['selftest'])) {
						self::log('info', 'oauth.discovery', 'Sent the authorization-server metadata (step 2 of an app connecting).');
					}

					self::sendJson(self::authorizationServerMetadata(), 200, true);
					break;
				case 'register':
					self::handleRegister($app);
					break;
				case 'authorize':
					self::handleAuthorize($app);
					break;
				case 'token':
					self::handleToken($app);
					break;
				case 'revoke':
					self::handleRevoke($app);
					break;
			}
		} catch (\Throwable $e) {
			ActivityLog::exception('oauth', 'oauth.' . $route, $e, self::$logCtx);
			self::sendJson([
				'error'             => 'server_error',
				'error_description' => 'The authorization server hit an error. A site administrator can see the details under Components > MCP for Joomla > Logs.',
			], 500, true);
		}

		$app->close();
	}

	/** Write an OAuth activity-log entry with this request's app and user. */
	private static function log(string $status, string $event, string $summary, array $context = []): void
	{
		ActivityLog::{$status}('oauth', $event, $summary, array_merge(self::$logCtx, $context));
	}

	/** Log a refused OAuth request and send the JSON error. */
	private static function refuse(string $event, string $error, string $description, int $status = 400): void
	{
		self::log('warning', $event, 'Refused (' . $error . '): ' . $description, ['details' => ['http_status' => $status]]);
		self::sendJson(['error' => $error, 'error_description' => $description], $status, true);
	}

	// ------------------------------------------------------------------
	// Dynamic Client Registration (RFC 7591)
	// ------------------------------------------------------------------

	private static function handleRegister(CMSApplication $app): void
	{
		if (self::method() === 'OPTIONS') {
			self::sendCorsPreflight();

			return;
		}

		if (self::method() !== 'POST') {
			self::refuse('oauth.register', 'invalid_request', 'Use POST.', 405);

			return;
		}

		$db = self::db();
		$ip = self::ip();

		$recent = (int) $db->setQuery(
			$db->getQuery(true)
				->select('COUNT(*)')
				->from($db->quoteName(self::T_CLIENTS))
				->where($db->quoteName('created_ip') . ' = :ip')
				->where($db->quoteName('created') . ' > :since')
				->bind(':ip', $ip)
				->bind(':since', self::sqlDate(-3600))
		)->loadResult();

		if ($recent >= self::REGISTRATIONS_PER_HOUR) {
			self::refuse('oauth.register', 'invalid_request', 'Too many registrations from this address. Try again later.', 429);

			return;
		}

		$body = json_decode((string) file_get_contents('php://input'), true);

		if (!is_array($body)) {
			self::refuse('oauth.register', 'invalid_client_metadata', 'The request body must be JSON.');

			return;
		}

		$uris = $body['redirect_uris'] ?? null;

		if (!is_array($uris) || $uris === [] || count($uris) > self::MAX_REDIRECT_URIS) {
			self::refuse('oauth.register', 'invalid_redirect_uri', 'redirect_uris must list 1 to ' . self::MAX_REDIRECT_URIS . ' URIs.');

			return;
		}

		$clean = [];

		foreach ($uris as $uri) {
			if (!is_string($uri) || !self::isAcceptableRedirectUri($uri)) {
				self::refuse('oauth.register', 'invalid_redirect_uri', 'Redirect URIs must be https, a loopback http address, or an app-specific scheme. Got: ' . (is_string($uri) ? mb_substr($uri, 0, 200) : gettype($uri)));

				return;
			}

			$clean[] = $uri;
		}

		$authMethod = (string) ($body['token_endpoint_auth_method'] ?? 'none');

		if (!in_array($authMethod, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
			self::refuse('oauth.register', 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method: ' . mb_substr($authMethod, 0, 40));

			return;
		}

		$grantTypes = isset($body['grant_types']) && is_array($body['grant_types'])
			? array_values(array_intersect($body['grant_types'], ['authorization_code', 'refresh_token']))
			: ['authorization_code', 'refresh_token'];

		if (!in_array('authorization_code', $grantTypes, true)) {
			self::refuse('oauth.register', 'invalid_client_metadata', 'grant_types must include authorization_code.');

			return;
		}

		$name = trim(strip_tags((string) ($body['client_name'] ?? '')));
		$name = $name === '' ? 'Unnamed MCP client' : mb_substr($name, 0, 200);

		$clientId = 'mcpj_client_' . bin2hex(random_bytes(16));
		$secret   = $authMethod === 'none' ? '' : bin2hex(random_bytes(32));
		$now      = self::sqlDate();

		$row = (object) [
			'client_id'          => $clientId,
			'client_secret_hash' => $secret === '' ? '' : hash('sha256', $secret),
			'auth_method'        => $authMethod,
			'client_name'        => $name,
			'redirect_uris'      => json_encode($clean, JSON_UNESCAPED_SLASHES),
			'created'            => $now,
			'created_ip'         => $ip,
			'revoked'            => 0,
		];
		$db->insertObject(self::T_CLIENTS, $row);

		$response = [
			'client_id'                  => $clientId,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $clean,
			'grant_types'                => $grantTypes,
			'response_types'             => ['code'],
			'token_endpoint_auth_method' => $authMethod,
			'scope'                      => self::SCOPE,
		];

		if ($secret !== '') {
			$response['client_secret']            = $secret;
			$response['client_secret_expires_at'] = 0;
		}

		self::log('success', 'oauth.register', 'Registered the app "' . $name . '".', [
			'subject'   => $name,
			'client_id' => $clientId,
			'details'   => ['redirect_uris' => $clean, 'token_endpoint_auth_method' => $authMethod, 'grant_types' => $grantTypes],
		]);

		self::sendJson($response, 201, true);
	}

	/**
	 * https anywhere; plain http only to a loopback address (RFC 8252 §7.3);
	 * or a private-use app scheme such as cursor:// or vscode:// (§7.1).
	 * Never javascript:, data:, file: or similar.
	 */
	private static function isAcceptableRedirectUri(string $uri): bool
	{
		if (strlen($uri) > 2000 || str_contains($uri, '#')) {
			return false;
		}

		$parts  = parse_url($uri);
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));

		if ($scheme === 'https') {
			return !empty($parts['host']);
		}

		if ($scheme === 'http') {
			return in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
		}

		if ($scheme === '' || !preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme)) {
			return false;
		}

		return !in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws', 'wss'], true);
	}

	// ------------------------------------------------------------------
	// Authorization endpoint (code + PKCE, Joomla login, consent)
	// ------------------------------------------------------------------

	private static function handleAuthorize(CMSApplication $app): void
	{
		$isPost = self::method() === 'POST';
		$in     = $isPost ? $app->getInput()->post : $app->getInput()->get;

		$req = [
			'response_type'         => (string) $in->getString('response_type', ''),
			'client_id'             => (string) $in->getString('client_id', ''),
			'redirect_uri'          => (string) $in->getString('redirect_uri', ''),
			'state'                 => (string) $in->getString('state', ''),
			'code_challenge'        => (string) $in->getString('code_challenge', ''),
			'code_challenge_method' => (string) $in->getString('code_challenge_method', ''),
			'scope'                 => (string) $in->getString('scope', ''),
			'resource'              => (string) $in->getString('resource', ''),
		];

		// 1. Client and redirect URI first. Until both check out we must NOT
		//    redirect anywhere, or this endpoint becomes an open redirector.
		$client = self::findClient($req['client_id']);

		if ($client === null) {
			self::log('warning', 'oauth.authorize', 'Sign-in page refused: the app is not registered here, or was revoked.', [
				'client_id' => mb_substr($req['client_id'], 0, 64),
			]);
			self::renderPage('This app is not registered', '<p>The app that sent you here is not registered with this site, or its access was revoked. Go back to the app and add the connector again.</p>', 400);

			return;
		}

		self::$logCtx = ['subject' => (string) $client->client_name, 'client_id' => (string) $client->client_id];

		$registered = json_decode((string) $client->redirect_uris, true) ?: [];

		if ($req['redirect_uri'] === '' && count($registered) === 1) {
			$req['redirect_uri'] = (string) $registered[0];
		}

		if (!in_array($req['redirect_uri'], $registered, true)) {
			self::log('warning', 'oauth.authorize', 'Sign-in page refused: the app asked to return to an address it did not register.', [
				'details' => ['requested' => mb_substr($req['redirect_uri'], 0, 500), 'registered' => $registered],
			]);
			self::renderPage('Invalid redirect address', '<p>The app asked to return to an address it did not register. For your safety this request was stopped.</p>', 400);

			return;
		}

		// 2. Everything else is reported back to the app per RFC 6749 §4.1.2.1.
		if ($req['response_type'] !== 'code') {
			self::redirectWithError($req, 'unsupported_response_type', 'Only response_type=code is supported.');

			return;
		}

		if ($req['code_challenge'] === '' || $req['code_challenge_method'] !== 'S256') {
			self::redirectWithError($req, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.');

			return;
		}

		if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $req['code_challenge'])) {
			self::redirectWithError($req, 'invalid_request', 'Malformed code_challenge.');

			return;
		}

		if ($req['resource'] !== '' && !self::resourceMatches($req['resource'])) {
			self::redirectWithError($req, 'invalid_target', 'This server only issues tokens for its own MCP endpoint.');

			return;
		}

		// 3. Joomla login (the site's own login page, so its MFA applies).
		$user = $app->getIdentity();

		if ($user === null || $user->guest) {
			self::log('info', 'oauth.authorize', 'Not signed in yet: sent the browser to this site\'s login page.');
			$return = self::endpoint('authorize') . '&' . http_build_query(array_filter($req, static fn ($v) => $v !== ''));
			$app->redirect(Uri::root() . 'index.php?option=com_users&view=login&return=' . base64_encode($return));

			return;
		}

		self::$logCtx['user_id'] = (int) $user->id;

		if (!self::userMayUseMcp($user)) {
			self::log('warning', 'oauth.authorize', $user->username . ' signed in but may not use MCP (needs Web Services Login plus MCP for Joomla access).', [
				'details' => [
					'core.login.api'      => $user->authorise('core.login.api'),
					'csmcpforj.use'       => $user->authorise('csmcpforj.use', 'com_csmcpforj'),
					'core.manage (com_)'  => $user->authorise('core.manage', 'com_csmcpforj'),
					'groups'              => array_values($user->getAuthorisedGroups()),
				],
			]);
			self::renderPage(
				'Your account cannot use MCP',
				'<p>You are signed in as <strong>' . self::e($user->name) . '</strong> (' . self::e($user->username) . '), but this account is not allowed to use MCP for Joomla on this site.</p>'
				. '<p>A Super User can allow it: the account\'s group needs Joomla\'s <em>Web Services Login</em> permission (System &rarr; Global Configuration &rarr; Permissions), plus access to MCP for Joomla in the component\'s Options &rarr; Permissions.</p>',
				403
			);

			return;
		}

		// 4. Consent.
		if (!$isPost) {
			self::log('info', 'oauth.authorize', 'Showed the Allow / Deny page to ' . $user->username . '.');
			self::renderConsent($req, $client, $user);

			return;
		}

		if (!Session::checkToken('post')) {
			self::log('warning', 'oauth.authorize', 'Allow / Deny answer refused: the form token had expired (session timed out or cookies blocked).');
			self::renderPage('Session expired', '<p>Your session expired before you answered. Go back to the app and try connecting again.</p>', 400);

			return;
		}

		if ($app->getInput()->post->getCmd('decision', '') !== 'allow') {
			self::redirectWithError($req, 'access_denied', 'The user declined.');

			return;
		}

		$code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$db   = self::db();

		// A variable, never a cast literal: insertObject() takes the object by
		// reference, and PHP 8 throws on a literal (2.9.0 test builds failed
		// every sign-in here; found by Stefan Gagner).
		$codeRow = (object) [
			'code_hash'      => hash('sha256', $code),
			'client_id'      => (string) $client->client_id,
			'user_id'        => (int) $user->id,
			'redirect_uri'   => $req['redirect_uri'],
			'code_challenge' => $req['code_challenge'],
			'resource'       => self::resourceUrl(),
			'scope'          => self::SCOPE,
			'expires'        => self::sqlDate(self::CODE_TTL_SECONDS),
			'used'           => 0,
			'created'        => self::sqlDate(),
		];
		$db->insertObject(self::T_CODES, $codeRow);

		self::actionLog('COM_CSMCPFORJ_ACTIONLOG_OAUTH_CONSENT', (int) $user->id, (string) $client->client_name);
		self::log('success', 'oauth.authorize', $user->username . ' allowed "' . $client->client_name . '"; sent the app back with an authorization code.', [
			'details' => ['redirect_host' => (string) parse_url($req['redirect_uri'], PHP_URL_HOST)],
		]);

		self::redirectTo($req['redirect_uri'], ['code' => $code, 'state' => $req['state'], 'iss' => self::issuer()]);
	}

	private static function userMayUseMcp(User $user): bool
	{
		if (!$user->authorise('core.login.api')) {
			return false;
		}

		foreach (['csmcpforj.use', 'core.manage', 'core.admin'] as $action) {
			if ($user->authorise($action, 'com_csmcpforj')) {
				return true;
			}
		}

		return false;
	}

	private static function renderConsent(array $req, object $client, User $user): void
	{
		$host   = (string) (parse_url($req['redirect_uri'], PHP_URL_HOST) ?: parse_url($req['redirect_uri'], PHP_URL_SCHEME));
		$site   = (string) Factory::getApplication()->get('sitename', 'this site');
		$hidden = '';

		foreach ($req as $k => $v) {
			$hidden .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
		}

		$hidden .= '<input type="hidden" name="' . Session::getFormToken() . '" value="1">';

		$body = '<p class="lead"><strong>' . self::e((string) $client->client_name) . '</strong> wants to connect to <strong>' . self::e($site) . '</strong> through MCP for Joomla.</p>'
			. '<ul>'
			. '<li>It will act as <strong>' . self::e($user->name) . '</strong> (' . self::e($user->username) . '), with exactly the permissions this account has on the site, including making changes if the account can.</li>'
			. '<li>After you allow it, you will be sent back to <strong>' . self::e($host) . '</strong>.</li>'
			. '<li>You can disconnect it at any time under Components &rarr; MCP for Joomla.</li>'
			. '</ul>'
			. '<p class="muted">Only allow apps you added yourself. If you did not just add a connector, choose Deny.</p>'
			. '<form method="post" action="' . self::e(self::endpoint('authorize')) . '">' . $hidden
			. '<button type="submit" name="decision" value="allow" class="allow">Allow</button> '
			. '<button type="submit" name="decision" value="deny" class="deny">Deny</button>'
			. '</form>';

		self::renderPage('Connect ' . (string) $client->client_name . '?', $body, 200, false);
	}

	private static function resourceMatches(string $resource): bool
	{
		$norm = static function (string $url): string {
			$p = parse_url($url);

			return strtolower((string) ($p['scheme'] ?? '')) . '://' . strtolower((string) ($p['host'] ?? ''))
				. (isset($p['port']) ? ':' . $p['port'] : '') . rtrim((string) ($p['path'] ?? ''), '/');
		};

		return $norm($resource) === $norm(self::resourceUrl());
	}

	private static function redirectWithError(array $req, string $error, string $description): void
	{
		self::log($error === 'access_denied' ? 'info' : 'warning', 'oauth.authorize', 'Sent the app back with ' . $error . ': ' . $description);

		self::redirectTo($req['redirect_uri'], [
			'error'             => $error,
			'error_description' => $description,
			'state'             => $req['state'],
			'iss'               => self::issuer(),
		]);
	}

	private static function redirectTo(string $uri, array $params): void
	{
		$params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
		$target = $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params);

		header('Cache-Control: no-store');
		header('Location: ' . $target, true, 302);
	}

	// ------------------------------------------------------------------
	// Token endpoint
	// ------------------------------------------------------------------

	private static function handleToken(CMSApplication $app): void
	{
		if (self::method() === 'OPTIONS') {
			self::sendCorsPreflight();

			return;
		}

		if (self::method() !== 'POST') {
			self::tokenError('invalid_request', 'Use POST.', 405);

			return;
		}

		$post   = $app->getInput()->post;
		$grant  = (string) $post->getString('grant_type', '');
		$client = self::authenticateClient($app);

		if ($client === null) {
			self::tokenError('invalid_client', 'Unknown client, revoked client, or bad client credentials.', 401, self::$clientFailure);

			return;
		}

		self::$logCtx = ['subject' => (string) $client->client_name, 'client_id' => (string) $client->client_id];

		if ($grant === 'authorization_code') {
			self::grantAuthorizationCode($app, $client);

			return;
		}

		if ($grant === 'refresh_token') {
			self::grantRefreshToken($app, $client);

			return;
		}

		self::tokenError('unsupported_grant_type', 'Use authorization_code or refresh_token. Got: ' . mb_substr($grant, 0, 60));
	}

	private static function authenticateClient(CMSApplication $app): ?object
	{
		$post     = $app->getInput()->post;
		$clientId = (string) $post->getString('client_id', '');
		$secret   = (string) $post->getString('client_secret', '');

		$auth = (string) $app->getInput()->server->get('HTTP_AUTHORIZATION', '', 'string');

		if ($auth === '') {
			$auth = (string) $app->getInput()->server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
		}

		if (stripos($auth, 'Basic ') === 0) {
			$decoded = (string) base64_decode(substr($auth, 6), true);

			if (str_contains($decoded, ':')) {
				[$basicId, $basicSecret] = explode(':', $decoded, 2);
				$clientId = urldecode($basicId);
				$secret   = urldecode($basicSecret);
			}
		}

		$client = self::findClient($clientId);

		if ($client === null) {
			self::$clientFailure = $clientId === ''
				? 'No client_id was sent (neither in the form body nor in HTTP Basic auth).'
				: 'client_id ' . mb_substr($clientId, 0, 64) . ' is not registered here, or was revoked.';

			return null;
		}

		if ((string) $client->auth_method !== 'none') {
			if ($secret === '' || !hash_equals((string) $client->client_secret_hash, hash('sha256', $secret))) {
				self::$clientFailure = 'The app "' . $client->client_name . '" registered with ' . $client->auth_method
					. ' but sent ' . ($secret === '' ? 'no client secret' : 'a wrong client secret') . '.';

				return null;
			}
		}

		return $client;
	}

	private static function grantAuthorizationCode(CMSApplication $app, object $client): void
	{
		$post     = $app->getInput()->post;
		$code     = (string) $post->getString('code', '');
		$verifier = (string) $post->getString('code_verifier', '');
		$redirect = (string) $post->getString('redirect_uri', '');
		$resource = (string) $post->getString('resource', '');
		$db       = self::db();

		if ($code === '' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
			self::tokenError('invalid_request', 'code and a valid code_verifier are required.');

			return;
		}

		$row = $db->setQuery(
			$db->getQuery(true)
				->select('*')
				->from($db->quoteName(self::T_CODES))
				->where($db->quoteName('code_hash') . ' = :h')
				->bind(':h', hash('sha256', $code))
		)->loadObject();

		if (!$row || (string) $row->client_id !== (string) $client->client_id) {
			self::tokenError('invalid_grant', 'Unknown or mismatched authorization code.');

			return;
		}

		if ((int) $row->used === 1) {
			// A code presented twice means it leaked: kill what it produced.
			self::revokeWhere(['client_id' => (string) $row->client_id, 'user_id' => (int) $row->user_id, 'created_since' => (string) $row->created]);
			self::tokenError('invalid_grant', 'Authorization code already used.');

			return;
		}

		// Mark used first, and only if it was still unused, so two parallel
		// requests cannot both redeem it.
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName(self::T_CODES))
				->set($db->quoteName('used') . ' = 1')
				->where($db->quoteName('id') . ' = ' . (int) $row->id)
				->where($db->quoteName('used') . ' = 0')
		)->execute();

		if ($db->getAffectedRows() !== 1) {
			self::tokenError('invalid_grant', 'Authorization code already used.');

			return;
		}

		if (strtotime((string) $row->expires . ' UTC') < time()) {
			self::tokenError('invalid_grant', 'Authorization code expired.');

			return;
		}

		if ($redirect !== '' && $redirect !== (string) $row->redirect_uri) {
			self::tokenError('invalid_grant', 'redirect_uri does not match the authorization request.');

			return;
		}

		$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

		if (!hash_equals((string) $row->code_challenge, $challenge)) {
			self::tokenError('invalid_grant', 'PKCE verification failed.');

			return;
		}

		if ($resource !== '' && !self::resourceMatches($resource)) {
			self::tokenError('invalid_target', 'This server only issues tokens for its own MCP endpoint.');

			return;
		}

		$user = self::loadActiveUser((int) $row->user_id);
		self::$logCtx['user_id'] = (int) $row->user_id;

		if ($user === null) {
			self::tokenError('invalid_grant', 'The user who authorized this app can no longer sign in.');

			return;
		}

		$tokens = self::issueTokens((string) $client->client_id, $user, bin2hex(random_bytes(16)));
		self::log('success', 'oauth.token', 'Issued access and refresh tokens to "' . $client->client_name . '" for ' . $user->username . '. The app is connected.', [
			'details' => ['grant_type' => 'authorization_code', 'access_token_lifetime_s' => self::accessTtl()],
		]);
		self::sendJson($tokens, 200, true);
	}

	private static function grantRefreshToken(CMSApplication $app, object $client): void
	{
		$token = (string) $app->getInput()->post->getString('refresh_token', '');
		$db    = self::db();

		if (!str_starts_with($token, self::REFRESH_PREFIX)) {
			self::tokenError('invalid_grant', 'Invalid refresh token.');

			return;
		}

		$row = $db->setQuery(
			$db->getQuery(true)
				->select('*')
				->from($db->quoteName(self::T_TOKENS))
				->where($db->quoteName('token_hash') . ' = :h')
				->where($db->quoteName('token_type') . ' = ' . $db->quote('refresh'))
				->bind(':h', hash('sha256', $token))
		)->loadObject();

		if (!$row || (string) $row->client_id !== (string) $client->client_id) {
			self::tokenError('invalid_grant', 'Invalid refresh token.');

			return;
		}

		if ((int) $row->revoked === 1) {
			// Reuse of a rotated-out refresh token: assume theft, end the family.
			self::revokeWhere(['family' => (string) $row->family]);
			self::tokenError('invalid_grant', 'Refresh token was already used. Sign in again.');

			return;
		}

		if (strtotime((string) $row->expires . ' UTC') < time()) {
			self::tokenError('invalid_grant', 'Refresh token expired. Sign in again.');

			return;
		}

		$user = self::loadActiveUser((int) $row->user_id);
		self::$logCtx['user_id'] = (int) $row->user_id;

		if ($user === null || !hash_equals((string) $row->pw_fingerprint, self::passwordFingerprint($user))) {
			self::revokeWhere(['family' => (string) $row->family]);
			self::tokenError('invalid_grant', 'The user who authorized this app can no longer sign in, or changed their password.');

			return;
		}

		// Rotate: the old refresh token (and its access token) end now.
		$db->setQuery(
			$db->getQuery(true)
				->update($db->quoteName(self::T_TOKENS))
				->set($db->quoteName('revoked') . ' = 1')
				->where($db->quoteName('id') . ' = ' . (int) $row->id)
				->where($db->quoteName('revoked') . ' = 0')
		)->execute();

		if ($db->getAffectedRows() !== 1) {
			self::revokeWhere(['family' => (string) $row->family]);
			self::tokenError('invalid_grant', 'Refresh token was already used. Sign in again.');

			return;
		}

		$tokens = self::issueTokens((string) $client->client_id, $user, (string) $row->family);
		self::log('success', 'oauth.token', 'Renewed tokens for "' . $client->client_name . '" (' . $user->username . ').', [
			'details' => ['grant_type' => 'refresh_token'],
		]);
		self::sendJson($tokens, 200, true);
	}

	private static function issueTokens(string $clientId, User $user, string $family): array
	{
		$db      = self::db();
		$access  = self::ACCESS_PREFIX . bin2hex(random_bytes(32));
		$refresh = self::REFRESH_PREFIX . bin2hex(random_bytes(32));
		$now     = self::sqlDate();
		$fp      = self::passwordFingerprint($user);

		foreach ([['access', $access, self::accessTtl()], ['refresh', $refresh, self::refreshTtl()]] as [$type, $value, $ttl]) {
			// A variable, not a cast literal: insertObject() takes it by
			// reference (same bug as the authorization code, see above).
			$tokenRow = (object) [
				'token_hash'     => hash('sha256', $value),
				'token_type'     => $type,
				'family'         => $family,
				'client_id'      => $clientId,
				'user_id'        => (int) $user->id,
				'pw_fingerprint' => $fp,
				'resource'       => self::resourceUrl(),
				'scope'          => self::SCOPE,
				'expires'        => self::sqlDate($ttl),
				'created'        => $now,
				'revoked'        => 0,
			];
			$db->insertObject(self::T_TOKENS, $tokenRow);
		}

		self::purgeExpired();

		return [
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::accessTtl(),
			'refresh_token' => $refresh,
			'scope'         => self::SCOPE,
		];
	}

	// ------------------------------------------------------------------
	// Revocation endpoint (RFC 7009)
	// ------------------------------------------------------------------

	private static function handleRevoke(CMSApplication $app): void
	{
		if (self::method() === 'OPTIONS') {
			self::sendCorsPreflight();

			return;
		}

		$client = self::authenticateClient($app);
		$token  = (string) $app->getInput()->post->getString('token', '');

		if ($client !== null && $token !== '') {
			$db  = self::db();
			$row = $db->setQuery(
				$db->getQuery(true)
					->select([$db->quoteName('family'), $db->quoteName('client_id')])
					->from($db->quoteName(self::T_TOKENS))
					->where($db->quoteName('token_hash') . ' = :h')
					->bind(':h', hash('sha256', $token))
			)->loadObject();

			if ($row && (string) $row->client_id === (string) $client->client_id) {
				$count = self::revokeWhere(['family' => (string) $row->family]);
				self::log('info', 'oauth.revoke', 'The app "' . $client->client_name . '" signed out; ' . $count . ' token(s) revoked.', [
					'subject'   => (string) $client->client_name,
					'client_id' => (string) $client->client_id,
				]);
			}
		}

		// RFC 7009 §2.2: respond 200 whether or not the token was known.
		self::sendJson([], 200, true);
	}

	// ------------------------------------------------------------------
	// Validation on the MCP route (API application)
	// ------------------------------------------------------------------

	/**
	 * Validate an OAuth access token. Returns ['user_id', 'client_id'] or null.
	 * Cached per request: the system plugin and the api-authentication plugin
	 * both ask.
	 */
	public static function validateAccessToken(string $token): ?array
	{
		if (array_key_exists($token, self::$validated)) {
			return self::$validated[$token];
		}

		self::$validated[$token] = self::doValidate($token);

		return self::$validated[$token];
	}

	/** Why validateAccessToken() rejected this token, in plain words. */
	public static function rejectionReason(string $token): string
	{
		return self::$rejections[hash('sha256', $token)] ?? 'unknown reason';
	}

	private static function doValidate(string $token): ?array
	{
		$hash   = hash('sha256', $token);
		$reject = static function (string $why) use ($hash): ?array {
			self::$rejections[$hash] = $why;

			return null;
		};

		if (!self::isEnabled()) {
			return $reject('OAuth sign-in is switched off in Options.');
		}

		if (!str_starts_with($token, self::ACCESS_PREFIX) || strlen($token) > 200) {
			return $reject('Not an MCP for Joomla access token.');
		}

		$db  = self::db();
		$row = $db->setQuery(
			$db->getQuery(true)
				->select('t.*')
				->from($db->quoteName(self::T_TOKENS, 't'))
				->join('INNER', $db->quoteName(self::T_CLIENTS, 'c') . ' ON ' . $db->quoteName('c.client_id') . ' = ' . $db->quoteName('t.client_id'))
				->select([$db->quoteName('c.client_name'), $db->quoteName('c.revoked', 'c_revoked')])
				->where($db->quoteName('t.token_hash') . ' = :h')
				->where($db->quoteName('t.token_type') . ' = ' . $db->quote('access'))
				->bind(':h', $hash)
		)->loadObject();

		if (!$row) {
			return $reject('Unknown access token (never issued here, or deleted after expiry).');
		}

		if ((int) $row->revoked === 1) {
			return $reject('The access token was revoked (renewed, signed out, or disconnected on the Dashboard).');
		}

		if ((int) ($row->c_revoked ?? 0) === 1) {
			return $reject('The app "' . $row->client_name . '" was disconnected on the Dashboard.');
		}

		if (strtotime((string) $row->expires . ' UTC') < time()) {
			return $reject('The access token expired at ' . $row->expires . ' UTC; the app should renew it with its refresh token.');
		}

		if (!self::resourceMatches((string) $row->resource)) {
			return $reject('The token was issued for ' . $row->resource . ', not for ' . self::resourceUrl() . ' (site URL changed?).');
		}

		$user = self::loadActiveUser((int) $row->user_id);

		if ($user === null) {
			return $reject('User #' . (int) $row->user_id . ' is blocked, deleted, unactivated, or must reset their password.');
		}

		if (!hash_equals((string) $row->pw_fingerprint, self::passwordFingerprint($user))) {
			return $reject($user->username . ' changed their password since signing in; the app must sign in again.');
		}

		// Touch last_used at most every five minutes, to keep writes down.
		if (empty($row->last_used) || strtotime((string) $row->last_used . ' UTC') < time() - 300) {
			$db->setQuery(
				$db->getQuery(true)
					->update($db->quoteName(self::T_TOKENS))
					->set($db->quoteName('last_used') . ' = ' . $db->quote(self::sqlDate()))
					->where($db->quoteName('id') . ' = ' . (int) $row->id)
			)->execute();
		}

		return ['user_id' => (int) $row->user_id, 'client_id' => (string) $row->client_id];
	}

	// ------------------------------------------------------------------
	// Admin: list and revoke
	// ------------------------------------------------------------------

	/** Active authorisations, one row per app + user. */
	public static function listAuthorisations(): array
	{
		$db = self::db();

		return $db->setQuery(
			$db->getQuery(true)
				->select([
					$db->quoteName('t.client_id'),
					$db->quoteName('t.user_id'),
					$db->quoteName('c.client_name'),
					$db->quoteName('u.name', 'user_name'),
					$db->quoteName('u.username'),
					'MIN(' . $db->quoteName('t.created') . ') AS ' . $db->quoteName('first_seen'),
					'MAX(' . $db->quoteName('t.last_used') . ') AS ' . $db->quoteName('last_used'),
				])
				->from($db->quoteName(self::T_TOKENS, 't'))
				->join('INNER', $db->quoteName(self::T_CLIENTS, 'c') . ' ON ' . $db->quoteName('c.client_id') . ' = ' . $db->quoteName('t.client_id'))
				->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('t.user_id'))
				->where($db->quoteName('t.revoked') . ' = 0')
				->where($db->quoteName('c.revoked') . ' = 0')
				->where($db->quoteName('t.expires') . ' > ' . $db->quote(self::sqlDate()))
				->group([$db->quoteName('t.client_id'), $db->quoteName('t.user_id'), $db->quoteName('c.client_name'), $db->quoteName('u.name'), $db->quoteName('u.username')])
				->order($db->quoteName('first_seen') . ' DESC')
		)->loadObjectList() ?: [];
	}

	/** Revoke one app's access for one user (or for everyone when $userId is 0). */
	public static function revokeAuthorisation(string $clientId, int $userId, int $actorId): int
	{
		$conditions = ['client_id' => $clientId];

		if ($userId > 0) {
			$conditions['user_id'] = $userId;
		}

		$count = self::revokeWhere($conditions);

		if ($count > 0) {
			$client = self::findClient($clientId, true);
			self::actionLog('COM_CSMCPFORJ_ACTIONLOG_OAUTH_REVOKE', $actorId, $client ? (string) $client->client_name : $clientId);
		}

		return $count;
	}

	private static function revokeWhere(array $conditions): int
	{
		$db = self::db();
		$q  = $db->getQuery(true)
			->update($db->quoteName(self::T_TOKENS))
			->set($db->quoteName('revoked') . ' = 1')
			->where($db->quoteName('revoked') . ' = 0');

		if (isset($conditions['family'])) {
			$q->where($db->quoteName('family') . ' = ' . $db->quote($conditions['family']));
		}

		if (isset($conditions['client_id'])) {
			$q->where($db->quoteName('client_id') . ' = ' . $db->quote($conditions['client_id']));
		}

		if (isset($conditions['user_id'])) {
			$q->where($db->quoteName('user_id') . ' = ' . (int) $conditions['user_id']);
		}

		if (isset($conditions['created_since'])) {
			$q->where($db->quoteName('created') . ' >= ' . $db->quote($conditions['created_since']));
		}

		$db->setQuery($q)->execute();
		self::$validated = [];

		return (int) $db->getAffectedRows();
	}

	private static function purgeExpired(): void
	{
		$db = self::db();

		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName(self::T_CODES))
				->where($db->quoteName('expires') . ' < ' . $db->quote(self::sqlDate(-86400)))
		)->execute();

		$db->setQuery(
			$db->getQuery(true)
				->delete($db->quoteName(self::T_TOKENS))
				->where($db->quoteName('expires') . ' < ' . $db->quote(self::sqlDate(-7 * 86400)))
		)->execute();
	}

	// ------------------------------------------------------------------
	// Schema (created by the package script on install and update)
	// ------------------------------------------------------------------

	public static function ensureSchema(DatabaseInterface $db): void
	{
		if (stripos($db->getServerType(), 'mysql') === false) {
			// Joomla also runs on PostgreSQL. These tables are MySQL/MariaDB
			// DDL; on PostgreSQL OAuth stays unavailable rather than half-made.
			return;
		}

		$charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		$db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::T_CLIENTS) . ' (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`client_id` varchar(64) NOT NULL,
			`client_secret_hash` char(64) NOT NULL DEFAULT \'\',
			`auth_method` varchar(32) NOT NULL DEFAULT \'none\',
			`client_name` varchar(255) NOT NULL DEFAULT \'\',
			`redirect_uris` text NOT NULL,
			`created` datetime NOT NULL,
			`created_ip` varchar(45) NOT NULL DEFAULT \'\',
			`revoked` tinyint NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_client_id` (`client_id`),
			KEY `idx_created_ip` (`created_ip`, `created`)
		) ' . $charset)->execute();

		$db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::T_CODES) . ' (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`code_hash` char(64) NOT NULL,
			`client_id` varchar(64) NOT NULL,
			`user_id` int NOT NULL,
			`redirect_uri` text NOT NULL,
			`code_challenge` varchar(128) NOT NULL,
			`resource` varchar(255) NOT NULL DEFAULT \'\',
			`scope` varchar(255) NOT NULL DEFAULT \'\',
			`expires` datetime NOT NULL,
			`used` tinyint NOT NULL DEFAULT 0,
			`created` datetime NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_code_hash` (`code_hash`)
		) ' . $charset)->execute();

		$db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName(self::T_TOKENS) . ' (
			`id` int unsigned NOT NULL AUTO_INCREMENT,
			`token_hash` char(64) NOT NULL,
			`token_type` varchar(10) NOT NULL,
			`family` varchar(64) NOT NULL,
			`client_id` varchar(64) NOT NULL,
			`user_id` int NOT NULL,
			`pw_fingerprint` char(64) NOT NULL,
			`resource` varchar(255) NOT NULL DEFAULT \'\',
			`scope` varchar(255) NOT NULL DEFAULT \'\',
			`expires` datetime NOT NULL,
			`created` datetime NOT NULL,
			`last_used` datetime NULL DEFAULT NULL,
			`revoked` tinyint NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_token_hash` (`token_hash`),
			KEY `idx_family` (`family`),
			KEY `idx_client_user` (`client_id`, `user_id`)
		) ' . $charset)->execute();
	}

	public static function dropSchema(DatabaseInterface $db): void
	{
		foreach ([self::T_TOKENS, self::T_CODES, self::T_CLIENTS] as $table) {
			$db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function findClient(string $clientId, bool $includeRevoked = false): ?object
	{
		if ($clientId === '' || strlen($clientId) > 64) {
			return null;
		}

		$db = self::db();
		$q  = $db->getQuery(true)
			->select('*')
			->from($db->quoteName(self::T_CLIENTS))
			->where($db->quoteName('client_id') . ' = :id')
			->bind(':id', $clientId);

		if (!$includeRevoked) {
			$q->where($db->quoteName('revoked') . ' = 0');
		}

		return $db->setQuery($q)->loadObject() ?: null;
	}

	/** The user, if they exist and may sign in at all. */
	private static function loadActiveUser(int $userId): ?User
	{
		if ($userId <= 0) {
			return null;
		}

		$user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);

		if (!$user || (int) $user->id !== $userId || $user->block || trim((string) $user->activation) !== '' || $user->requireReset) {
			return null;
		}

		return $user;
	}

	/**
	 * Changes whenever the user's password changes, without storing anything
	 * derived from the password that would help an attacker.
	 */
	private static function passwordFingerprint(User $user): string
	{
		return hash_hmac('sha256', (string) $user->password, (string) Factory::getApplication()->get('secret', ''));
	}

	private static function actionLog(string $key, int $userId, string $appName): void
	{
		try {
			$user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);

			Factory::getApplication()->bootComponent('com_actionlogs')->getMVCFactory()
				->createModel('Actionlog', 'Administrator', ['ignore_request' => true])
				->addLog([[
					'action'      => 'oauth',
					'title'       => $appName,
					'userid'      => $userId,
					'username'    => (string) $user->username,
					'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
				]], $key, 'com_csmcpforj', $userId);
		} catch (\Throwable $e) {
			// Logging must never break the flow.
		}
	}

	private static function db(): DatabaseInterface
	{
		return Factory::getContainer()->get(DatabaseInterface::class);
	}

	private static function sqlDate(int $offsetSeconds = 0): string
	{
		return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
	}

	private static function ip(): string
	{
		return substr((string) IpHelper::getIp(), 0, 45);
	}

	private static function method(): string
	{
		return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	}

	private static function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private static function tokenError(string $error, string $description, int $status = 400, string $detail = ''): void
	{
		$grant = (string) ($_POST['grant_type'] ?? '');

		self::log('warning', 'oauth.token', 'Token request refused (' . $error . '): ' . ($detail !== '' ? $detail : $description), [
			'details' => ['grant_type' => mb_substr($grant, 0, 40), 'http_status' => $status],
		]);

		if ($status === 401) {
			header('WWW-Authenticate: Basic realm="mcp"');
		}

		self::sendJson(['error' => $error, 'error_description' => $description], $status, true);
	}

	private static function sendCorsPreflight(): void
	{
		http_response_code(204);
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
		header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
		header('Access-Control-Max-Age: 86400');
	}

	private static function sendJson(array $payload, int $status, bool $cors): void
	{
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('Pragma: no-cache');

		if ($cors) {
			header('Access-Control-Allow-Origin: *');
		}

		echo json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private static function renderPage(string $title, string $bodyHtml, int $status = 200, bool $escapeTitle = true): void
	{
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Frame-Options: DENY');
		header("Content-Security-Policy: frame-ancestors 'none'");
		header('Referrer-Policy: no-referrer');

		$site = self::e((string) Factory::getApplication()->get('sitename', 'Joomla'));

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . self::e($title) . ' - ' . $site . '</title><style>'
			. ':root{color-scheme:light dark}body{font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f4f5f7;color:#1f2328}'
			. 'main{max-width:560px;margin:48px auto;padding:28px 32px;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
			. 'h1{font-size:1.35rem;margin:0 0 4px}.site{color:#57606a;font-size:.9rem;margin:0 0 20px}.lead{font-size:1.05rem}.muted{color:#57606a;font-size:.92rem}'
			. 'button{font:inherit;padding:10px 22px;border-radius:6px;border:1px solid transparent;cursor:pointer;margin-top:8px}'
			. '.allow{background:#1f7a3a;color:#fff}.deny{background:transparent;border-color:#8c959f;color:inherit}'
			. '@media (prefers-color-scheme:dark){body{background:#0d1117;color:#e6edf3}main{background:#161b22;box-shadow:none}.site,.muted{color:#9da7b3}}'
			. '</style></head><body><main><h1>' . self::e($title) . '</h1><p class="site">' . $site . ' &middot; MCP for Joomla</p>'
			. $bodyHtml . '</main></body></html>';
	}
}
