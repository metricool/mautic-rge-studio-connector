<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Settings;

use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectorSettingsTest extends TestCase
{
    /**
     * Secret values the environment held before the test, put back afterwards.
     *
     * @var array<string, array{env: mixed, server: mixed, process: string|false}>
     */
    private array $saved = [];

    /**
     * Keep the secrets the environment already holds, then start every test without them.
     *
     * @return void
     */
    protected function setUp(): void
    {
        foreach ([ConnectorSettings::SECRET_ENV, ConnectorSettings::PREVIOUS_SECRET_ENV] as $name) {
            $this->saved[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null, 'process' => getenv($name)];
            $this->clear($name);
        }
    }

    /**
     * Remove the secrets a test set and put back the ones the environment held before.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $saved) {
            $this->clear($name);
            if (null !== $saved['env']) {
                $_ENV[$name] = $saved['env'];
            }
            if (null !== $saved['server']) {
                $_SERVER[$name] = $saved['server'];
            }
            if (false !== $saved['process']) {
                putenv($name.'='.$saved['process']);
            }
        }
    }

    /**
     * The secrets come from the parameters, the current one first, empty ones left out.
     *
     * @return void
     */
    public function testSecretsComeFromTheParameters(): void
    {
        self::assertSame(['current', 'previous'], $this->settings(['rge_studio_connector_secret' => 'current', 'rge_studio_connector_previous_secret' => 'previous'])->secrets());
        self::assertSame(['previous'], $this->settings(['rge_studio_connector_secret' => '', 'rge_studio_connector_previous_secret' => 'previous'])->secrets());
        self::assertSame([], $this->settings(['rge_studio_connector_secret' => '', 'rge_studio_connector_previous_secret' => ''])->secrets());
    }

    /**
     * A secret in the environment wins over the parameter, and an empty one is ignored.
     *
     * @return void
     */
    public function testSecretsInTheEnvironmentWin(): void
    {
        $_ENV[ConnectorSettings::SECRET_ENV]           = 'from-env';
        $_SERVER[ConnectorSettings::SECRET_ENV]        = 'from-server';
        $_SERVER[ConnectorSettings::PREVIOUS_SECRET_ENV] = 'previous-from-server';

        self::assertSame(['from-env', 'previous-from-server'], $this->settings(['rge_studio_connector_secret' => 'current', 'rge_studio_connector_previous_secret' => 'previous'])->secrets());

        $_ENV[ConnectorSettings::SECRET_ENV] = '';
        unset($_SERVER[ConnectorSettings::SECRET_ENV]);
        self::assertSame(['current', 'previous-from-server'], $this->settings(['rge_studio_connector_secret' => 'current'])->secrets());
    }

    /**
     * A secret set only through putenv is read too.
     *
     * @return void
     */
    public function testSecretFromGetenvIsRead(): void
    {
        putenv(ConnectorSettings::SECRET_ENV.'=from-getenv');

        self::assertSame(['from-getenv'], $this->settings(['rge_studio_connector_secret' => 'current'])->secrets());
    }

    /**
     * Text settings are trimmed where it matters and read as strings.
     *
     * @return void
     */
    public function testTextSettings(): void
    {
        $settings = $this->settings([
            'rge_studio_connector_secret_header' => 123,
            'rge_studio_connector_project'       => '  Designs  ',
            'rge_studio_connector_campaign_tag'  => ' For-Campaign ',
            'rge_studio_connector_segment_tag'   => ' For-Segment ',
            'unsubscribe_text'                   => "<a href='|URL|'>Leave</a>",
        ]);

        self::assertSame('123', $settings->secretHeader());
        self::assertSame('Designs', $settings->projectName());
        self::assertSame('for-campaign', $settings->campaignTag());
        self::assertSame('for-segment', $settings->segmentTag());
        self::assertSame("<a href='|URL|'>Leave</a>", $settings->unsubscribeTextSetting());
    }

    /**
     * Missing settings fall back to the plugin defaults.
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $names    = ['secret_header', 'user_id', 'project', 'campaign_tag', 'segment_tag', 'marker_unsubscribe', 'marker_images', 'marker_other', 'image_folder', 'max_body_bytes', 'max_image_bytes', 'max_images', 'image_seconds', 'image_attempts', 'claim_seconds', 'wait_seconds'];
        $settings = $this->settings(array_fill_keys(array_map(static fn (string $name): string => 'rge_studio_connector_'.$name, $names), null));

        self::assertSame('X-Webhook-Secret', $settings->secretHeader());
        self::assertSame(0, $settings->userId());
        self::assertSame('RGE Studio', $settings->projectName());
        self::assertSame('mautic-campaign', $settings->campaignTag());
        self::assertSame('mautic-segment', $settings->segmentTag());
        self::assertSame('[FIX UNSUBSCRIBE]', $settings->marker(ConnectorSettings::MARKER_UNSUBSCRIBE));
        self::assertSame('[FIX IMAGES]', $settings->marker(ConnectorSettings::MARKER_IMAGES));
        self::assertSame('[FIX EMAIL]', $settings->marker(ConnectorSettings::MARKER_OTHER));
        self::assertSame('[FIX EMAIL]', $settings->marker('unknown'));
        self::assertSame('rge-studio', $settings->imageFolder());
        self::assertSame(10485760, $settings->maxBodyBytes());
        self::assertSame(5242880, $settings->maxImageBytes());
        self::assertSame(150, $settings->maxImages());
        self::assertSame(25.0, $settings->imageSeconds());
        self::assertSame(2, $settings->imageAttempts());
        self::assertSame(60, $settings->claimSeconds());
        self::assertSame(35.0, $settings->waitSeconds());
    }

    /**
     * Markers can be changed and are trimmed.
     *
     * @return void
     */
    public function testMarkersCanBeChanged(): void
    {
        $settings = $this->settings(['rge_studio_connector_marker_images' => ' [IMG] ']);

        self::assertSame('[IMG]', $settings->marker(ConnectorSettings::MARKER_IMAGES));
        self::assertSame('[FIX UNSUBSCRIBE]', $settings->marker(ConnectorSettings::MARKER_UNSUBSCRIBE));
    }

    /**
     * The image folder is one safe folder name, or the default.
     *
     * @dataProvider imageFolders
     *
     * @param string $configured Folder as configured
     * @param string $expected   Folder used
     *
     * @return void
     */
    #[DataProvider('imageFolders')]
    public function testImageFolder(string $configured, string $expected): void
    {
        self::assertSame($expected, $this->settings(['rge_studio_connector_image_folder' => $configured])->imageFolder());
    }

    /**
     * A plain name, a name with slashes around it, and names that would leave the images folder.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function imageFolders(): iterable
    {
        yield 'plain' => ['designs', 'designs'];
        yield 'slashes around' => ['/designs/', 'designs'];
        yield 'path' => ['good/../bad', 'rge-studio'];
        yield 'space' => ['bad folder', 'rge-studio'];
    }

    /**
     * Numbers are read from numeric text too, and anything else falls back to the default.
     *
     * @return void
     */
    public function testNumbersFromText(): void
    {
        $settings = $this->settings([
            'rge_studio_connector_user_id'       => '7',
            'rge_studio_connector_max_images'    => 'many',
            'rge_studio_connector_image_seconds' => '12.5',
            'rge_studio_connector_wait_seconds'  => 'soon',
        ]);

        self::assertSame(7, $settings->userId());
        self::assertSame(150, $settings->maxImages());
        self::assertSame(12.5, $settings->imageSeconds());
        self::assertSame(35.0, $settings->waitSeconds());
    }

    /**
     * Every limit has a floor, and a value at the floor is kept.
     *
     * @dataProvider floors
     *
     * @param string                                   $name  Parameter name without the plugin prefix
     * @param int|float                                $below Value under the floor
     * @param int|float                                $floor Floor
     * @param callable(ConnectorSettings): (int|float) $read  Reads the setting
     *
     * @return void
     */
    #[DataProvider('floors')]
    public function testLimitsHaveAFloor(string $name, int|float $below, int|float $floor, callable $read): void
    {
        self::assertSame($floor, $read($this->settings(['rge_studio_connector_'.$name => $below])));
        self::assertSame($floor, $read($this->settings(['rge_studio_connector_'.$name => $floor])));
    }

    /**
     * Each limit with its floor.
     *
     * @return iterable<string, array{string, int|float, int|float, callable(ConnectorSettings): (int|float)}>
     */
    public static function floors(): iterable
    {
        yield 'body' => ['max_body_bytes', 1023, 1024, static fn (ConnectorSettings $settings): int => $settings->maxBodyBytes()];
        yield 'image' => ['max_image_bytes', 1023, 1024, static fn (ConnectorSettings $settings): int => $settings->maxImageBytes()];
        yield 'images' => ['max_images', 0, 1, static fn (ConnectorSettings $settings): int => $settings->maxImages()];
        yield 'image seconds' => ['image_seconds', 0.5, 1.0, static fn (ConnectorSettings $settings): float => $settings->imageSeconds()];
        yield 'attempts' => ['image_attempts', 0, 1, static fn (ConnectorSettings $settings): int => $settings->imageAttempts()];
        yield 'claim' => ['claim_seconds', 9, 10, static fn (ConnectorSettings $settings): int => $settings->claimSeconds()];
        yield 'wait' => ['wait_seconds', -1, 0.0, static fn (ConnectorSettings $settings): float => $settings->waitSeconds()];
    }

    /**
     * Lists come from arrays or comma separated text, trimmed, without empty items.
     *
     * @return void
     */
    public function testLists(): void
    {
        $settings = $this->settings([
            'rge_studio_connector_accepted_statuses'        => 'approved, in_review,,',
            'rge_studio_connector_designer_hosts'           => [' Media.BeeFree.cloud ', '', null, 5],
            'rge_studio_connector_pasted_unsubscribe_paths' => 'not a list item',
        ]);

        self::assertSame(['approved', 'in_review'], $settings->acceptedStatuses());
        self::assertSame(['media.beefree.cloud', '5'], $settings->designerHosts());
        self::assertSame(['not a list item'], $settings->pastedUnsubscribePaths());
        self::assertSame([], $this->settings(['rge_studio_connector_accepted_statuses' => 12.5])->acceptedStatuses());
    }

    /**
     * Mautic's base addresses come without a trailing slash, the static address first.
     *
     * @return void
     */
    public function testMauticBaseUrls(): void
    {
        self::assertSame(
            ['https://cdn.example.com', 'https://mautic.example.com'],
            $this->settings(['static_url' => 'https://cdn.example.com/', 'site_url' => 'https://mautic.example.com/'])->mauticBaseUrls()
        );
        self::assertSame(['https://mautic.example.com'], $this->settings(['static_url' => '', 'site_url' => 'https://mautic.example.com'])->mauticBaseUrls());
    }

    /**
     * Tags are compared trimmed and in lower case, accented letters included.
     *
     * @return void
     */
    public function testTagsAreNormalised(): void
    {
        self::assertSame('été', $this->settings()->normaliseTag(' ÉTÉ '));
    }

    /**
     * Settings over the plugin defaults.
     *
     * @param array<string, mixed> $parameters Parameters to change
     *
     * @return ConnectorSettings
     */
    private function settings(array $parameters = []): ConnectorSettings
    {
        return SettingsStub::create($parameters);
    }

    /**
     * Remove one secret from every place the settings read it from.
     *
     * @param string $name Environment variable name
     *
     * @return void
     */
    private function clear(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    }
}
