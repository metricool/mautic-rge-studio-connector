<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;

final class SettingsStub
{
    /**
     * Plugin settings over a fixed set of Mautic parameters.
     *
     * @param array<string, mixed> $parameters Parameters by name, merged over the plugin defaults
     *
     * @return ConnectorSettings
     */
    public static function create(array $parameters = []): ConnectorSettings
    {
        $config = require __DIR__.'/../../Config/config.php';
        $values = array_merge(
            $config['parameters'],
            ['site_url' => 'https://mautic.example.com', 'static_url' => null, 'unsubscribe_text' => "<a href='|URL|'>Unsubscribe</a>"],
            $parameters
        );

        $helper = new class($values) extends CoreParametersHelper {
            /**
             * Build the stub.
             *
             * @param array<string, mixed> $values Parameters by name
             */
            public function __construct(private array $values)
            {
            }

            /**
             * Parameter value or the default.
             *
             * @param string $name    Parameter name
             * @param mixed  $default Value when missing
             *
             * @return mixed
             */
            public function get($name, $default = null)
            {
                return array_key_exists($name, $this->values) && null !== $this->values[$name] ? $this->values[$name] : $default;
            }
        };

        return new ConnectorSettings($helper);
    }
}
