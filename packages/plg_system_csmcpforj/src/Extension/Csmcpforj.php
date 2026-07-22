<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Cybersalt\Component\Csmcpforj\Administrator\MCP\ToolRegistry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
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
			'onAfterInitialise'            => 'onAfterInitialise',
			RegisterToolsEvent::EVENT_NAME => 'onRegisterTools',
		];
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
		$accept = (string) $app->input->server->get('HTTP_ACCEPT', '', 'string');
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
		if ($method === 'GET' && !$hasBearer) {
			$root    = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
			$payload = [
				'service'         => 'cs-mcp-for-j',
				'description'     => 'Model Context Protocol (MCP) endpoint for this Joomla site. Lets MCP clients (Claude Desktop, Claude Code, Cursor, Continue, Cline, etc.) call the site\'s registered tools over JSON-RPC 2.0.',
				'endpoint'        => $root . '/api/index.php/v1/mcp',
				'protocol'        => 'JSON-RPC 2.0 over HTTP',
				'method'          => 'POST (only — GET returns this info response)',
				'content_type'    => 'application/json',
				'authentication'  => 'Bearer <joomla-api-token> in the Authorization header (Joomla API tokens are created at User Profile → Joomla API Token)',
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
				'note' => 'You are seeing this response because you hit the endpoint with GET (e.g. clicked the URL in a browser). MCP clients use POST and you do not interact with this URL directly — the client talks to it for you.',
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
