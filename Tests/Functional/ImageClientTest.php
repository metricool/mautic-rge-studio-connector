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
     * fetches one, carrier-grade and NAT64 ranges included.
     *
     * @return void
     */
    public function testImagesAreDownloadedThroughThePrivateNetworkGuard(): void
    {
        $container = static::getContainer();
        $client    = $container->get('rge_studio_connector.http_client');

        $used = (new \ReflectionProperty(ImageImporter::class, 'httpClient'))->getValue($container->get(ImageImporter::class));
        self::assertSame($client, $used);

        foreach (['http://127.0.0.1/internal.png', 'http://100.64.3.7/internal.png', 'http://198.18.0.9/internal.png', 'http://[64:ff9b::a00:1]/internal.png'] as $url) {
            try {
                $client->request('GET', $url, ['timeout' => 2, 'max_duration' => 2])->getStatusCode();
                self::fail('The request to '.$url.' was not blocked.');
            } catch (TransportExceptionInterface $exception) {
                self::assertMatchesRegularExpression('/^(Host|IP) "[^"]+" is blocked for "'.preg_quote($url, '/').'"\.$/', $exception->getMessage());
            }
        }
    }
}
