<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Helper\ProActivationHelper;
use Cybersalt\Component\Csmcpforj\Administrator\Log\ActivityLog;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\OAuth\OAuthServer;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;

/**
 * System plugin for cs-mcp-for-j.
 *
 * Two responsibilities:
 *  1. onAfterInitialise — translate Authorization: Bearer <token> into
 *     X-Joomla-Token for our route only, so MCP clients that only support
 *     Bearer auth can still authenticate against Joomla's API token plugin.
 *  2. onCsMcpRegisterTools — register the v1 built-in tool set.
 */
final class Csmcpforj extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	/**
	 * Built-in tool classes, organised by domain. Each entry is the class name
	 * of an AbstractTool subclass. Tools are constructed with the database
	 * dependency only.
	 */
	private const BUILTIN_TOOLS = [
		'Articles' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\ListCategoriesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\ListArticlesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\GetArticleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\CreateArticleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\UpdateArticleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Articles\DeleteArticleTool::class,
		],
		'Categories' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\ListCategoriesInTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\GetCategoryTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\CreateCategoryTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\UpdateCategoryTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Categories\DeleteCategoryTool::class,
		],
		'Tags' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Tags\ListTagsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Tags\GetTagTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Tags\CreateTagTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Tags\UpdateTagTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Tags\DeleteTagTool::class,
		],
		'Menus' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\ListMenusTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\ListMenuItemsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\ListMenuItemTypesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\GetMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\CreateMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\UpdateMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\DeleteMenuItemTool::class,
		],
		'Admin Menu Presets' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu\ListAdminMenuPresetsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu\GetAdminMenuPresetTool::class,
		],
		'Users' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListUsersTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\GetUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\CreateUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\UpdateUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\DeleteUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListUserGroupsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListAccessLevelsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\GetUserApiTokenStatusTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\EnableUserApiTokenTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ResetUserApiTokenTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\RevokeUserApiTokenTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\CreateUserGroupTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\UpdateUserGroupTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\DeleteUserGroupTool::class,
		],
		'Permissions' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions\ListComponentPermissionsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Permissions\SetComponentPermissionTool::class,
		],
		'Modules' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\ListModulesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\ListModulePositionsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\ListModuleTypesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\GetModuleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\CreateModuleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\UpdateModuleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\DeleteModuleTool::class,
		],
		'Extensions' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\ListExtensionsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\ListPluginsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\SetExtensionEnabledTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\GetPluginParamsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\SetPluginParamsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Extensions\InstallExtensionTool::class,
		],
		'Templates' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\ListTemplateStylesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\GetTemplateStyleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\UpdateTemplateStyleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\SetDefaultTemplateStyleTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\ListTemplateFilesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\ReadTemplateFileTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\WriteTemplateFileTool::class,
		],
		'Languages' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Languages\ListLanguagesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Languages\ListContentLanguagesTool::class,
		],
		'Custom Fields' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\ListCustomFieldsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\GetCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\CreateCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\UpdateCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\DeleteCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\SetFieldValueTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\ListFieldGroupsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\GetFieldGroupTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\CreateFieldGroupTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\UpdateFieldGroupTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\DeleteFieldGroupTool::class,
		],
		'System' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\GetJoomlaVersionTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\GetSiteInfoTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\ClearCacheTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\CheckForUpdatesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\ListScheduledTasksTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\FetchRenderedUrlTool::class,
		],
		'Joomla Update' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate\CheckJoomlaUpdateTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate\JoomlaUpdateHealthcheckTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\JoomlaUpdate\ApplyJoomlaUpdateTool::class,
		],
		'Schema.org (SEO)' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\ListSchemaTypesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\ListArticlesWithSchemaTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\GetArticleSchemaTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\SetArticleSchemaTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\SetArticleCustomJsonldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\SetArticleCustomJsonldBulkTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\ClearArticleSchemaTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\ValidateJsonldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\GetSchemaorgSiteProfileTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\SchemaOrg\SetSchemaorgSiteProfileTool::class,
		],
	];

	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterInitialise'                => 'onAfterInitialise',
			RegisterToolsEvent::EVENT_NAME     => 'onRegisterTools',
			'onInstallerBeforePackageDownload' => 'onInstallerBeforePackageDownload',
			// Lowest priority: Joomla's own "Extension - Joomla" plugin creates an
			// extension's update site in this same event. System plugins are
			// registered first, so at default priority this ran BEFORE the row
			// existed and a freshly installed add-on stayed unstamped (seen on
			// mcpfree while testing 2.8.3).
			'onExtensionAfterInstall'          => ['onExtensionAfterInstallOrUpdate', Priority::MIN],
			'onExtensionAfterUpdate'           => ['onExtensionAfterInstallOrUpdate', Priority::MIN],
		];
	}

	/**
	 * Put this site's download ID on any Cybersalt members download that is
	 * about to go out without one.
	 *
	 * Joomla's own Extensions > Update appends the update site's extra_query
	 * itself, but remote managers do not use that code. mySites.guru's
	 * connector (bfnetwork, installExtensionFromUrl) downloads exactly the URL
	 * its server read from our feed, and every paid add-on failed "Missing
	 * parameters" on every site whose update sites had never been stamped —
	 * which was most of them, because stamping only happened on a dashboard
	 * visit. Found 2026-10-09 on a mass update that failed across ten sites.
	 *
	 * Every route into the installer funnels through
	 * InstallerHelper::downloadPackage(), which fires this event, so fixing it
	 * here covers mySites.guru, Watchful, Install from URL and the core
	 * updater alike, stamped or not. A URL that already carries a dlid is left
	 * alone, and nothing is added for any other host or route.
	 */
	public function onInstallerBeforePackageDownload($event): void
	{
		try {
			if (!method_exists($event, 'updateUrl')) {
				return;
			}

			$url = (string) $event->getUrl();

			// Check the URL BEFORE touching the helper. class_exists() autoloads,
			// and loading ProActivationHelper during MCP for Joomla's own update
			// pins the OLD class in memory, so the new package's postflight would
			// run old code (2.8.3).
			if (!self::isCybersaltMembersDownload($url) || preg_match('/(?:[?&]|&amp;)dlid=/', $url)) {
				return;
			}

			if (!class_exists(ProActivationHelper::class)) {
				return;
			}

			$dlid = ProActivationHelper::getDlid();

			if ($dlid === '') {
				return;
			}

			$event->updateUrl($url . (str_contains($url, '?') ? '&' : '?') . 'dlid=' . rawurlencode($dlid));
		} catch (\Throwable $e) {
			// Never break a download over this. The worst case is the
			// pre-2.8.2 behaviour: the store refuses it and says why.
		}
	}

	/**
	 * Re-stamp the dlid onto Cybersalt update sites whenever any extension is
	 * installed or updated. An add-on installed after the site was linked
	 * creates a fresh, unstamped update site; before this it stayed unstamped
	 * until someone happened to open the MCP for Joomla dashboard. The write
	 * is a no-op when every row already matches.
	 */
	public function onExtensionAfterInstallOrUpdate($event): void
	{
		try {
			if (class_exists(ProActivationHelper::class)) {
				ProActivationHelper::syncUpdateSiteDlid();
			}
		} catch (\Throwable $e) {
			// Bookkeeping only; never fail someone else's install over it.
		}
	}

	private static function isCybersaltMembersDownload(string $url): bool
	{
		$parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
		$host  = strtolower((string) ($parts['host'] ?? ''));

		// The dlid is a credential: never send it over plain http, even to our
		// own host. (Security review 2026-10-10, LOW.)
		if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		if ($host !== 'cybersalt.com' && $host !== 'www.cybersalt.com') {
			return false;
		}

		parse_str((string) ($parts['query'] ?? ''), $query);

		return ($query['option'] ?? '') === 'com_csreleasemanager'
			&& ($query['task'] ?? '') === 'api.download';
	}

	/**
	 * Translate Authorization: Bearer to X-Joomla-Token for the MCP route,
	 * normalise the Accept header so spec-compliant MCP clients survive
	 * Joomla's API content negotiation, AND intercept unauthenticated GETs
	 * to emit the discovery JSON before Joomla's API auth middleware can
	 * 401 them.
	 *
	 * Runs early so Joomla's plg_api-authentication_token sees the header.
	 */
	public function onAfterInitialise(): void
	{
		$app = $this->getApplication();
		if ($app === null) {
			return;
		}

		$uri = (string) $app->input->server->get('REQUEST_URI', '', 'string');

		// OAuth (#36): the authorization-server endpoints and the well-known
		// metadata documents are answered here, before Joomla routes, because
		// /.well-known/ sits at the site root where no component owns it. The
		// match is a cheap string test so ordinary page loads pay nothing.
		if ($app->isClient('site')) {
			$oauthRoute = OAuthServer::matchRequest(
				(string) parse_url($uri, PHP_URL_PATH),
				isset($_GET[OAuthServer::QUERY_KEY]) ? (string) $_GET[OAuthServer::QUERY_KEY] : null
			);

			if ($oauthRoute !== null && OAuthServer::isEnabled()) {
				OAuthServer::handle($oauthRoute, $app);
			}

			return;
		}

		if ($uri === '' || strpos($uri, '/api/index.php/v1/mcp') === false) {
			return;
		}

		// ACCEPT NORMALIZATION — the MCP Streamable HTTP spec requires
		// clients to send "Accept: application/json, text/event-stream" on
		// every POST, but Joomla's ApiApplication negotiates the Accept
		// header against the formats registered on the route (only
		// application/vnd.api+json) and throws 406 "Could not match accept
		// header" before McpController ever runs. Claude Code's transport
		// sets the spec header itself and overrides any custom Accept the
		// user configures, so the fix has to live server-side. This endpoint
		// only ever emits JSON, so coercing the header for our route is
		// lossless.
		$accept         = (string) $app->input->server->get('HTTP_ACCEPT', '', 'string');
		$originalAccept = $accept;
		if (stripos($accept, 'application/vnd.api+json') === false) {
			$app->input->server->set('HTTP_ACCEPT', 'application/vnd.api+json');
			$_SERVER['HTTP_ACCEPT'] = 'application/vnd.api+json';
		}

		// Collect the inbound Bearer token (if any) so both branches below can
		// inspect it. Empty string means "no token" — which is the case for
		// browsers landing on the URL via the dashboard.
		$auth = (string) $app->input->server->get('HTTP_AUTHORIZATION', '', 'string');
		if ($auth === '') {
			$auth = (string) $app->input->server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
		}
		$hasBearer = $auth !== '' && stripos($auth, 'Bearer ') === 0
			&& trim(substr($auth, 7)) !== '';

		$method = strtoupper((string) $app->input->server->get('REQUEST_METHOD', 'GET', 'string'));

		// OAUTH (#36). Only when the site has switched OAuth on; with it off
		// this block does nothing and API tokens behave exactly as before.
		if (OAuthServer::isEnabled()) {
			$bearer = $hasBearer ? trim(substr($auth, 7)) : '';

			if (str_starts_with($bearer, OAuthServer::ACCESS_PREFIX)) {
				$grant = OAuthServer::validateAccessToken($bearer);

				if ($grant === null) {
					// Expired, revoked or unknown: say so in the challenge so
					// the client refreshes or sends the user to sign in again.
					ActivityLog::warning('auth', 'auth.token', 'OAuth access token refused at the MCP endpoint: ' . OAuthServer::rejectionReason($bearer), [
						'details' => ['http_method' => $method, 'answer' => '401 invalid_token'],
					]);
					$this->sendOAuthChallenge($app, 'invalid_token', 'The access token is invalid or has expired.');

					return;
				}

				// Every later log entry in this request names the OAuth app.
				ActivityLog::setRequestContext(['client_id' => $grant['client_id'], 'user_id' => $grant['user_id']]);

				// Valid. A GET is the client trying to open a server stream,
				// which this server does not offer: 405 so it stops asking
				// (see the GET-loop note below).
				if ($method === 'GET') {
					$this->sendMethodNotAllowed($app);

					return;
				}

				// Valid POST. Do NOT translate it into X-Joomla-Token: the
				// MCP for Joomla api-authentication plugin authenticates it.
				return;
			}

			// No credentials at all from an MCP client: answer with the 401
			// challenge the MCP authorization spec requires, naming where the
			// resource metadata lives. A plain browser GET still gets the
			// friendly discovery JSON below.
			$wantsStream = stripos($originalAccept, 'text/event-stream') !== false;

			if (!$hasBearer && $app->input->server->get('HTTP_X_JOOMLA_TOKEN', '', 'string') === ''
				&& ($method === 'POST' || ($method === 'GET' && $wantsStream))) {
				ActivityLog::info('auth', 'auth.challenge', 'An MCP client called without credentials; answered 401 pointing it at OAuth sign-in (normal first step of connecting).', [
					'details' => ['http_method' => $method],
				]);
				$this->sendOAuthChallenge($app);

				return;
			}
		}

		// EARLY GET DISCOVERY — if someone hits the MCP URL with GET and no
		// Bearer token (e.g. a browser opened from the dashboard's "MCP
		// endpoint" link), emit the same discovery JSON our McpController::info()
		// would emit. We have to do it here because Joomla's API auth middleware
		// runs BEFORE the API controller dispatches, and it rejects token-less
		// requests with a bare 401 Forbidden — which makes the dashboard's
		// "public for discovery" hint look like a lie.
		//
		// The actual MCP protocol surface (POST + Bearer token) is untouched —
		// this only fires for GET-without-token. Anyone with a token still
		// goes through normal API dispatch and reaches McpController::info()
		// or ::handle() as before.
		// GET-LOOP FIX (2.9.0). After `initialize`, Streamable-HTTP clients
		// (Claude Code and anything on the MCP SDKs) open a GET on the
		// endpoint, with their credentials, to get a server->client event
		// stream. The spec allows exactly two answers: an event stream, or
		// 405 Method Not Allowed. We used to answer 200 with the discovery
		// JSON, which the client read as "stream opened, then closed" and
		// reconnected at once, forever: 286,000 GETs in 10.5 h from one
		// session, and on 2026-09-12 enough load to take cybersalt.org and
		// cybersalt.com down for ten minutes. 405 makes it stop.
		//
		// A GET carrying a token or asking for a stream is an MCP client; a
		// plain browser GET (no token, no event-stream Accept) still gets the
		// friendly discovery JSON below.
		$hasJoomlaToken = $app->input->server->get('HTTP_X_JOOMLA_TOKEN', '', 'string') !== '';

		if ($method === 'GET' && ($hasBearer || $hasJoomlaToken || stripos($originalAccept, 'text/event-stream') !== false)) {
			$this->sendMethodNotAllowed($app);

			return;
		}

		if ($method === 'GET' && !$hasBearer) {
			$root    = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
			$payload = [
				'service'         => 'cs-mcp-for-j',
				'description'     => 'Model Context Protocol (MCP) endpoint for this Joomla site. Lets Codex and other conformant MCP clients (Claude, Cursor, Cline, Continue, ChatGPT custom connectors, GitHub Copilot, Gemini CLI, mcp-cli, etc.) call the site\'s registered tools over JSON-RPC 2.0.',
				'endpoint'        => $root . '/api/index.php/v1/mcp',
				'protocol'        => 'JSON-RPC 2.0 over HTTP',
				'method'          => 'POST (only — GET returns this info response)',
				'content_type'    => 'application/json',
				'authentication'  => 'Bearer <joomla-api-token> in the Authorization header (Joomla API tokens are created at User Profile → Joomla API Token), or OAuth 2.1 sign-in when the site has it switched on (discovery: /.well-known/oauth-protected-resource)',
				'example_request' => [
					'method'  => 'POST',
					'headers' => [
						'Authorization' => 'Bearer YOUR_JOOMLA_API_TOKEN_HERE',
						'Content-Type'  => 'application/json',
					],
					'body' => [
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					],
				],
				'note' => 'You are seeing this because you opened the endpoint in a browser. MCP clients talk to it with POST; a GET from an MCP client (with a token, or asking for an event stream) gets 405 Method Not Allowed, because this server does not offer a server-to-client stream.',
			];

			http_response_code(200);
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$app->close();
			return;
		}

		// POST with a Bearer token — fall through into the existing
		// Authorization → X-Joomla-Token translation so the standard
		// plg_api-authentication_token plugin picks it up.
		if ($app->input->server->get('HTTP_X_JOOMLA_TOKEN', '', 'string') !== '') {
			return;
		}

		if (!$hasBearer) {
			return;
		}

		$token = trim(substr($auth, 7));
		$app->input->server->set('HTTP_X_JOOMLA_TOKEN', $token);
		$_SERVER['HTTP_X_JOOMLA_TOKEN'] = $token;
	}

	/**
	 * 405 for an MCP client's GET: this server does not offer a
	 * server-to-client event stream (MCP Streamable HTTP: on GET the server
	 * MUST either return text/event-stream or 405 Method Not Allowed).
	 * Answered in PHP because a RewriteRule [R=405] in Joomla's .htaccess
	 * falls through to a 404.
	 */
	private function sendMethodNotAllowed($app): void
	{
		http_response_code(405);
		header('Allow: POST');
		header('Cache-Control: no-store');
		header('Content-Type: application/json; charset=utf-8');

		echo json_encode([
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => ['code' => -32601, 'message' => 'This server does not offer a server-to-client stream. Use POST.'],
		], JSON_UNESCAPED_SLASHES);

		$app->close();
	}

	/**
	 * 401 with the MCP authorization challenge (RFC 9728 §5.1).
	 */
	private function sendOAuthChallenge($app, string $error = '', string $description = ''): void
	{
		http_response_code(401);
		header('WWW-Authenticate: ' . OAuthServer::challengeHeader($error, $description));
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Expose-Headers: WWW-Authenticate');

		echo json_encode([
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => [
				'code'    => -32001,
				'message' => $error === ''
					? 'Authentication required. Sign in with OAuth, or send a Joomla API token as a Bearer token.'
					: $description,
			],
		], JSON_UNESCAPED_SLASHES);

		$app->close();
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::BUILTIN_TOOLS as $domain => $toolClasses) {
			foreach ($toolClasses as $toolClass) {
				$registry->register(new $toolClass($db));
			}
		}
	}

	/**
	 * Returns the BUILTIN_TOOLS map so the dashboard view can render tools
	 * grouped by domain without re-introspecting.
	 *
	 * @return array<string, array<int, class-string>>
	 */
	public static function getBuiltinTools(): array
	{
		return self::BUILTIN_TOOLS;
	}
}
