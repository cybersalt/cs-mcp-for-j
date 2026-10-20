<?php
/**
 * @package     Cybersalt MCP for Joomla
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\ApiAuthentication\Csmcpforj\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\OAuth\OAuthServer;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\SubscriberInterface;

/**
 * Authenticates MCP for Joomla OAuth access tokens on the MCP endpoint (#36).
 *
 * This has to be an api-authentication plugin: Joomla dispatches
 * onUserAuthenticate in an isolated dispatcher that loads only this plugin
 * group, so a system plugin never sees it. Running here means the normal
 * login path follows, including the core.login.api (Web Services Login)
 * check, so an OAuth token gets exactly the access an API token for the same
 * user would.
 *
 * It only ever acts on a token carrying the MCP for Joomla OAuth prefix, and
 * only on the MCP route: the token's audience is this site's MCP endpoint and
 * nothing else (RFC 8707). Any other credential is left alone for Joomla's
 * own token plugin.
 */
final class Csmcpforj extends CMSPlugin implements SubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return ['onUserAuthenticate' => 'onUserAuthenticate'];
	}

	public function onUserAuthenticate(AuthenticationEvent $event): void
	{
		$app = $this->getApplication();

		if ($app === null || !$app->isClient('api') || !class_exists(OAuthServer::class)) {
			return;
		}

		$server = $app->getInput()->server;

		if (strpos((string) $server->get('REQUEST_URI', '', 'string'), '/api/index.php/v1/mcp') === false) {
			return;
		}

		$auth = (string) $server->get('HTTP_AUTHORIZATION', '', 'string');

		if ($auth === '') {
			$auth = (string) $server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
		}

		if (stripos($auth, 'Bearer ') !== 0) {
			return;
		}

		$token = trim(substr($auth, 7));

		if (!str_starts_with($token, OAuthServer::ACCESS_PREFIX)) {
			return;
		}

		$response       = $event->getAuthenticationResponse();
		$response->type = 'MCPOAuth';
		$grant          = OAuthServer::validateAccessToken($token);

		if ($grant === null) {
			$response->status        = Authentication::STATUS_FAILURE;
			$response->error_message = 'Invalid or expired MCP OAuth access token.';

			return;
		}

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $grant['user_id']);

		if ($user->block || trim((string) $user->activation) !== '' || $user->requireReset) {
			$response->status = Authentication::STATUS_DENIED;

			return;
		}

		$response->status        = Authentication::STATUS_SUCCESS;
		$response->error_message = '';
		$response->username      = $user->username;
		$response->email         = $user->email;
		$response->fullname      = $user->name;
		$response->timezone      = $user->getParam('timezone', $app->get('offset', 'UTC'));
		$response->language      = $user->getParam('language', $app->get('language'));

		$event->stopPropagation();
	}
}
