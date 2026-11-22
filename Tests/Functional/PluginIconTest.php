<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\PluginBundle\Helper\IntegrationHelper;

final class PluginIconTest extends MauticMysqlTestCase
{
    /**
     * Mautic's plugin list shows the plugin's own icon, a 128 pixel square PNG, instead of the generic one.
     *
     * @return void
     */
    public function testPluginListShowsThePluginIcon(): void
    {
        $icon = static::getContainer()->get(IntegrationHelper::class)->getIconPath(['bundle' => 'RgeStudioConnectorBundle']);

        self::assertSame('plugins/RgeStudioConnectorBundle/Assets/img/icon.png', $icon);
        $size = getimagesize(dirname(__DIR__, 2).'/Assets/img/icon.png');
        self::assertIsArray($size);
        self::assertSame([128, 128, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]]);
    }
}
