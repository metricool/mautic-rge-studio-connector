<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class ImageClientTest extends MauticMysqlTestCase
{
    /**
     * The image importer downloads through the client that blocks private network addresses, and that client never
     * fetches one.
     *
     * @return void
     */
    public function testImagesAreDownloadedThroughThePrivateNetworkGuard(): void
    {
        $container = static::getContainer();
        $client    = $container->get('rge_studio_connector.http_client');

        $used = (new \ReflectionProperty(ImageImporter::class, 'httpClient'))->getValue($container->get(ImageImporter::class));
        self::assertSame($client, $used);

        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessageMatches('/^(Host|IP) "127\.0\.0\.1" is blocked for "http:\/\/127\.0\.0\.1\/internal\.png"\.$/');
        $client->request('GET', 'http://127.0.0.1/internal.png')->getStatusCode();
    }
}
