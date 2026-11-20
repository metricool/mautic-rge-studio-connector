<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class RgeStudioConnectorExtension extends Extension
{
    /**
     * Load the plugin's service definitions.
     *
     * @param mixed[]          $configs   Merged bundle configuration, unused
     * @param ContainerBuilder $container Container to register the services in
     *
     * @return void
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config'));
        $loader->load('services.php');
    }
}
