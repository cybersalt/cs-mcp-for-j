<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforj\Tools\AdminMenu;

\defined('_JEXEC') or die;

/**
 * Thrown by AdminMenuPresetPathTrait when a preset resolves cleanly but the
 * target file doesn't exist. Distinguishing this from InvalidArgumentException
 * lets the calling tool return a 404-shaped ToolResult::error instead of a
 * generic validation failure — the AI can tell "you asked for a preset that
 * isn't installed" apart from "you asked for something outside the allowlist".
 */
final class PresetNotFoundException extends \RuntimeException
{
}
