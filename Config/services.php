<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\ProjectBundle\Model\ProjectModel;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\MauticImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\NoImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\UnsubscribeLinkChecker;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

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
        'Import/Claim.php',
        'Import/ConnectorConfigurationException.php',
        'Import/ImageDownloadBatch.php',
        'Import/ImageImportResult.php',
        'Import/ImportBusyException.php',
        'Import/ImportResult.php',
        'vendor',
    ];

    $projects = class_exists(ProjectModel::class);
    if (!$projects) {
        $excludes[] = 'Import/MauticImportProject.php';
    }

    $services->load('MauticPlugin\\RgeStudioConnectorBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->alias(ImportProject::class, $projects ? MauticImportProject::class : NoImportProject::class);

    $services->set('rge_studio_connector.http_client', NoPrivateNetworkHttpClient::class)
        ->args([service('mautic.native.connector')]);

    $services->get(ImageImporter::class)
        ->arg('$httpClient', service('rge_studio_connector.http_client'));

    $services->get(UnsubscribeLinkChecker::class)
        ->arg('$headerRule', UnsubscribeLinkChecker::mauticHasHeaderRule());
};
