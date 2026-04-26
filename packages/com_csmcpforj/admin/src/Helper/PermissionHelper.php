<?php

declare(strict_types=1);

namespace Cybersalt\Component\Csmcpforj\Administrator\Helper;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\McpException;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;

final class PermissionHelper
{
	public const COMPONENT     = 'com_csmcpforj';
	public const ACTION_USE    = 'csmcpforj.use';
	public const ACTION_WRITE  = 'csmcpforj.write';

	/** Read-only / list operations. */
	public static function requireUse(User $user): void
	{
		self::requireAny($user, [self::ACTION_USE, 'core.manage', 'core.admin']);
	}

	/** Mutating operations. */
	public static function requireWrite(User $user): void
	{
		self::requireAny($user, [self::ACTION_WRITE, 'core.manage', 'core.admin']);
	}

	private static function requireAny(User $user, array $actions): void
	{
		if ($user->guest) {
			throw new McpException(-32001, Text::_('COM_CSMCPFORJ_ERROR_AUTH_REQUIRED'));
		}

		foreach ($actions as $action) {
			if ($user->authorise($action, self::COMPONENT)) {
				return;
			}
		}

		throw new McpException(-32002, Text::_('COM_CSMCPFORJ_ERROR_FORBIDDEN'));
	}
}
