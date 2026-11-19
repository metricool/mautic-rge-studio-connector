<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'Tests',
        'Payload/DesignExport.php',
        'Payload/InvalidExportException.php',
        'Import/ImageImportResult.php',
        'vendor',
    ];

    $services->load('MauticPlugin\\RgeStudioConnectorBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->set('rge_studio_connector.http_client', NoPrivateNetworkHttpClient::class)
        ->args([service('mautic.native.connector')]);

    $services->get(ImageImporter::class)
        ->arg('$httpClient', service('rge_studio_connector.http_client'));
};
