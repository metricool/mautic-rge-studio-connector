<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Payload;

final class DesignExport
{
    /**
     * Build one export as received from the Custom Connector.
     *
     * @param string       $designId     Stable id of the design, the last number of its edit address
     * @param string       $name         Design name
     * @param string       $subject      Email subject
     * @param string       $preheader    Preheader text
     * @param string       $html         Full email HTML
     * @param string       $status       Design status in RGE Studio
     * @param list<string> $tags         Design tags
     * @param string       $templateType Kind of design, "email" for emails
     * @param string       $hash         SHA-256 of the raw request body
     */
    public function __construct(
        public readonly string $designId,
        public readonly string $name,
        public readonly string $subject,
        public readonly string $preheader,
        public readonly string $html,
        public readonly string $status,
        public readonly array $tags,
        public readonly string $templateType,
        public readonly string $hash,
    ) {
    }

    /**
     * Whether the design is an email, the only kind imported.
     *
     * @return bool
     */
    public function isEmail(): bool
    {
        return 'email' === strtolower($this->templateType);
    }
}
