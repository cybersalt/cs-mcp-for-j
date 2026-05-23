<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Package install script.
 *
 * Class name MUST be {element}InstallerScript with the element exactly as
 * declared in the package manifest <packagename> (here: csmcpforj, with the
 * "pkg_" prefix). Joomla's InstallerAdapter::setupScriptfile() builds the
 * class name and only finds it under this exact spelling. An anonymous-class
 * return is not reliably detected by the package adapter, so use a named
 * class.
 */
class Pkg_csmcpforjInstallerScript implements InstallerScriptInterface
{
	private const ESCAPE_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

	public function install(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function update(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function uninstall(InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function preflight(string $type, InstallerAdapter $adapter): bool
	{
		return true;
	}

	public function postflight(string $type, InstallerAdapter $adapter): bool
	{
		// Joomla calls postflight() on uninstall too. Don't run install-only
		// side effects (or render the "click here to open" card) in that case.
		if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
			return true;
		}

		try {
			$this->clearAutoloadCache();
			$this->enableBundledPlugins();
		} catch (\Throwable $e) {
			// Surface unexpected install errors instead of silently swallowing.
			Factory::getApplication()->enqueueMessage(
				'cs-mcp-for-j postflight setup failed: ' . $e->getMessage(),
				'warning'
			);
		}

		$this->showPostInstallMessage($type);

		return true;
	}

	private function clearAutoloadCache(): void
	{
		// Plain @unlink rather than Joomla\CMS\Filesystem\File::delete():
		// the latter class was deprecated in J4, removed in J6, and bombs
		// the postflight on Joomla 6 sites with "Class not found". @unlink
		// works on every PHP version regardless of Joomla.
		$cacheFile = JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php';

		if (is_file($cacheFile)) {
			@unlink($cacheFile);
		}
	}

	private function enableBundledPlugins(): void
	{
		$db = Factory::getContainer()->get(DatabaseInterface::class);

		foreach (['system', 'webservices'] as $folder) {
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
				->where($db->quoteName('element') . ' = ' . $db->quote('csmcpforj'));

			$db->setQuery($query)->execute();
		}

		// Add-on plugins shipped in this package — enable on install/update.
		// Each add-on is a separate plugin so it can later be split into its
		// own paid SKU without restructuring the core.
		$addons = ['csmcpforj4seo', 'csmcpforjrst'];
		foreach ($addons as $element) {
			$query = $db->getQuery(true)
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 1')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
				->where($db->quoteName('element') . ' = ' . $db->quote($element));
			$db->setQuery($query)->execute();
		}
	}

	private function showPostInstallMessage(string $type): void
	{
		$messageKey = $type === 'update'
			? 'PKG_CSMCPFORJ_POSTINSTALL_UPDATED'
			: 'PKG_CSMCPFORJ_POSTINSTALL_INSTALLED';

		$title   = htmlspecialchars(Text::_('PKG_CSMCPFORJ'), self::ESCAPE_FLAGS, 'UTF-8');
		$message = htmlspecialchars(Text::_($messageKey), self::ESCAPE_FLAGS, 'UTF-8');
		$button  = htmlspecialchars(Text::_('PKG_CSMCPFORJ_POSTINSTALL_OPEN'), self::ESCAPE_FLAGS, 'UTF-8');
		$url     = 'index.php?option=com_csmcpforj';

		echo '<div class="card mb-3" style="margin: 20px 0;">'
			. '<div class="card-body">'
			. '<h3 class="card-title">' . $title . '</h3>'
			. '<p class="card-text">' . $message . '</p>'
			. '<a href="' . $url . '" class="btn btn-primary text-white">'
			. '<span class="icon-cog" aria-hidden="true"></span> '
			. $button
			. '</a></div></div>';
	}
}
