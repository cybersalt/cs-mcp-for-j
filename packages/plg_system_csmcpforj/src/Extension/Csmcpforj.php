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
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\GetMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\CreateMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\UpdateMenuItemTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Menus\DeleteMenuItemTool::class,
		],
		'Users' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListUsersTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\GetUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\CreateUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\UpdateUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\DeleteUserTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListUserGroupsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Users\ListAccessLevelsTool::class,
		],
		'Modules' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\ListModulesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Modules\ListModulePositionsTool::class,
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
		],
		'Templates' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\ListTemplateStylesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Templates\SetDefaultTemplateStyleTool::class,
		],
		'Languages' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Languages\ListLanguagesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Languages\ListContentLanguagesTool::class,
		],
		'Custom Fields' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\ListCustomFieldsTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\GetCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\CreateCustomFieldTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\Fields\SetFieldValueTool::class,
		],
		'System' => [
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\GetJoomlaVersionTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\GetSiteInfoTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\ClearCacheTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\CheckForUpdatesTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\ListScheduledTasksTool::class,
			\Cybersalt\Plugin\System\Csmcpforj\Tools\System\FetchRenderedUrlTool::class,
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
	 * Translate Authorization: Bearer to X-Joomla-Token for the MCP route.
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

		if ($app->input->server->get('HTTP_X_JOOMLA_TOKEN', '', 'string') !== '') {
			return;
		}

		$auth = (string) $app->input->server->get('HTTP_AUTHORIZATION', '', 'string');
		if ($auth === '') {
			$auth = (string) $app->input->server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
		}

		if ($auth === '' || stripos($auth, 'Bearer ') !== 0) {
			return;
		}

		$token = trim(substr($auth, 7));
		if ($token === '') {
			return;
		}

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
