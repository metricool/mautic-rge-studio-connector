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
        $config   = require __DIR__.'/../../Config/config.php';
        $defaults = is_array($config) && is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
        $values   = [];
        foreach ([$defaults, ['site_url' => 'https://mautic.example.com', 'static_url' => null, 'unsubscribe_text' => "<a href='|URL|'>Unsubscribe</a>"], $parameters] as $source) {
            foreach ($source as $name => $value) {
                $values[(string) $name] = $value;
            }
        }

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
            public function get($name, $default = null): mixed
            {
                return array_key_exists($name, $this->values) && null !== $this->values[$name] ? $this->values[$name] : $default;
            }
        };

        return new ConnectorSettings($helper);
    }
}
