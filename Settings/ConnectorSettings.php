<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Settings;

use Mautic\CoreBundle\Helper\CoreParametersHelper;

class ConnectorSettings
{
    public const SECRET_ENV          = 'RGE_STUDIO_CONNECTOR_SECRET';
    public const PREVIOUS_SECRET_ENV = 'RGE_STUDIO_CONNECTOR_PREVIOUS_SECRET';
    public const MARKER_UNSUBSCRIBE  = 'unsubscribe';
    public const MARKER_IMAGES       = 'images';
    public const MARKER_OTHER        = 'other';

    /**
     * Build the settings reader.
     *
     * @param CoreParametersHelper $parameters Mautic parameters
     */
    public function __construct(private CoreParametersHelper $parameters)
    {
    }

    /**
     * Secrets a caller may send, the current one first.
     *
     * Environment variables win over the Mautic parameters, so the secret can live outside the database.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $secrets = [
            $this->environment(self::SECRET_ENV) ?? $this->string('rge_studio_connector_secret'),
            $this->environment(self::PREVIOUS_SECRET_ENV) ?? $this->string('rge_studio_connector_previous_secret'),
        ];

        return array_values(array_filter($secrets, static fn (string $secret): bool => '' !== $secret));
    }

    /**
     * Name of the request header that carries the shared secret.
     *
     * @return string
     */
    public function secretHeader(): string
    {
        return $this->string('rge_studio_connector_secret_header', 'X-Webhook-Secret');
    }

    /**
     * Id of the Mautic user every import is saved as.
     *
     * @return int
     */
    public function userId(): int
    {
        return $this->integer('rge_studio_connector_user_id', 0);
    }

    /**
     * Name of the Mautic project every import is added to.
     *
     * @return string
     */
    public function projectName(): string
    {
        return trim($this->string('rge_studio_connector_project', 'RGE Studio'));
    }

    /**
     * Design tag that turns an export into a campaign email.
     *
     * @return string
     */
    public function campaignTag(): string
    {
        return $this->normaliseTag($this->string('rge_studio_connector_campaign_tag', 'mautic-campaign'));
    }

    /**
     * Design tag that asks for a segment email.
     *
     * @return string
     */
    public function segmentTag(): string
    {
        return $this->normaliseTag($this->string('rge_studio_connector_segment_tag', 'mautic-segment'));
    }

    /**
     * Design statuses that are imported, empty for every status.
     *
     * @return list<string>
     */
    public function acceptedStatuses(): array
    {
        return $this->stringList('rge_studio_connector_accepted_statuses');
    }

    /**
     * Text put at the start of an email's name for one kind of problem.
     *
     * @param string $kind One of the MARKER_* constants
     *
     * @return string
     */
    public function marker(string $kind): string
    {
        $defaults = [
            self::MARKER_UNSUBSCRIBE => '[FIX UNSUBSCRIBE]',
            self::MARKER_IMAGES      => '[FIX IMAGES]',
            self::MARKER_OTHER       => '[FIX EMAIL]',
        ];

        return trim($this->string('rge_studio_connector_marker_'.$kind, $defaults[$kind] ?? '[FIX EMAIL]'));
    }

    /**
     * Folder under Mautic's images path that holds copied images.
     *
     * @return string
     */
    public function imageFolder(): string
    {
        $folder = trim($this->string('rge_studio_connector_image_folder', 'rge-studio'), '/');

        return 1 === preg_match('/^[A-Za-z0-9_-]+$/', $folder) ? $folder : 'rge-studio';
    }

    /**
     * Hosts of the designer's own image storage, which must never stay in a saved email.
     *
     * @return list<string>
     */
    public function designerHosts(): array
    {
        return array_map('strtolower', $this->stringList('rge_studio_connector_designer_hosts'));
    }

    /**
     * Paths that show a pasted unsubscribe address instead of a token.
     *
     * @return list<string>
     */
    public function pastedUnsubscribePaths(): array
    {
        return $this->stringList('rge_studio_connector_pasted_unsubscribe_paths');
    }

    /**
     * Largest request body accepted, in bytes.
     *
     * @return int
     */
    public function maxBodyBytes(): int
    {
        return max(1024, $this->integer('rge_studio_connector_max_body_bytes', 10485760));
    }

    /**
     * Largest image copied, in bytes.
     *
     * @return int
     */
    public function maxImageBytes(): int
    {
        return max(1024, $this->integer('rge_studio_connector_max_image_bytes', 5242880));
    }

    /**
     * Most images copied from one export.
     *
     * @return int
     */
    public function maxImages(): int
    {
        return max(1, $this->integer('rge_studio_connector_max_images', 150));
    }

    /**
     * Seconds after the request arrived within which all image copies of one export must finish.
     *
     * When the wait for an identical export already used that time, the copies still get 10 seconds.
     *
     * @return float
     */
    public function imageSeconds(): float
    {
        return max(1.0, $this->number('rge_studio_connector_image_seconds', 25.0));
    }

    /**
     * Download attempts per image.
     *
     * @return int
     */
    public function imageAttempts(): int
    {
        return max(1, $this->integer('rge_studio_connector_image_attempts', 2));
    }

    /**
     * Seconds an unfinished import of a design blocks an identical export.
     *
     * @return int
     */
    public function claimSeconds(): int
    {
        return max(10, $this->integer('rge_studio_connector_claim_seconds', 60));
    }

    /**
     * Seconds after the request arrived that an export waits for another import of the same design to finish.
     *
     * @return float
     */
    public function waitSeconds(): float
    {
        return max(0.0, $this->number('rge_studio_connector_wait_seconds', 35.0));
    }

    /**
     * Mautic's unsubscribe text setting, used by the List-Unsubscribe header rule.
     *
     * @return string
     */
    public function unsubscribeTextSetting(): string
    {
        return $this->string('unsubscribe_text');
    }

    /**
     * Public base addresses of this Mautic, the static address first.
     *
     * @return list<string>
     */
    public function mauticBaseUrls(): array
    {
        $urls = [$this->string('static_url'), $this->string('site_url')];

        return array_values(array_filter(array_map(static fn (string $url): string => rtrim($url, '/'), $urls)));
    }

    /**
     * Lower-case tag used for comparisons.
     *
     * @param string $tag Tag as configured or received
     *
     * @return string
     */
    public function normaliseTag(string $tag): string
    {
        return mb_strtolower(trim($tag));
    }

    /**
     * Value of an environment variable, or null when it is not set or empty.
     *
     * @param string $name Variable name
     *
     * @return string|null
     */
    private function environment(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Parameter as a string.
     *
     * @param string $name    Parameter name
     * @param string $default Value when the parameter is missing
     *
     * @return string
     */
    private function string(string $name, string $default = ''): string
    {
        $value = $this->parameters->get($name, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Parameter as a whole number.
     *
     * @param string $name    Parameter name
     * @param int    $default Value when the parameter is missing or not a number
     *
     * @return int
     */
    private function integer(string $name, int $default): int
    {
        $value = $this->parameters->get($name, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Parameter as a number.
     *
     * @param string $name    Parameter name
     * @param float  $default Value when the parameter is missing or not a number
     *
     * @return float
     */
    private function number(string $name, float $default): float
    {
        $value = $this->parameters->get($name, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Parameter as a list of non-empty strings.
     *
     * @param string $name Parameter name
     *
     * @return list<string>
     */
    private function stringList(string $name): array
    {
        $value = $this->parameters->get($name, []);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $items = array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value);

        return array_values(array_filter($items, static fn (string $item): bool => '' !== $item));
    }
}
