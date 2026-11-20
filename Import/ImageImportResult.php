<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

final class ImageImportResult
{
    /**
     * Build the outcome of copying an export's images.
     *
     * @param string                                   $html     HTML pointing only to Mautic's copies, failed images emptied
     * @param int                                      $copied   Number of distinct images copied or already present
     * @param list<array{url: string, reason: string}> $failures Images not copied, with the reason
     */
    public function __construct(
        public readonly string $html,
        public readonly int $copied,
        public readonly array $failures,
    ) {
    }

    /**
     * Whether any image could not be copied.
     *
     * @return bool
     */
    public function hasFailures(): bool
    {
        return [] !== $this->failures;
    }
}
