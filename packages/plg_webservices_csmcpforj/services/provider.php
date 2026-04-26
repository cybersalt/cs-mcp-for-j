<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Cybersalt\Plugin\WebServices\Csmcpforj\Extension\Csmcpforj;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$plugin = new Csmcpforj(
					$container->get(DispatcherInterface::class),
					(array) PluginHelper::getPlugin('webservices', 'csmcpforj')
				);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
