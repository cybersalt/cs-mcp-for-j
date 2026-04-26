<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\Extension\CsmcpforjComponent;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->registerServiceProvider(new MVCFactory('\\Cybersalt\\Component\\Csmcpforj'));
		$container->registerServiceProvider(new ComponentDispatcherFactory('\\Cybersalt\\Component\\Csmcpforj'));

		$container->set(
			ComponentInterface::class,
			function (Container $container) {
				$component = new CsmcpforjComponent($container->get(ComponentDispatcherFactoryInterface::class));
				$component->setMVCFactory($container->get(MVCFactoryInterface::class));

				return $component;
			}
		);
	}
};
