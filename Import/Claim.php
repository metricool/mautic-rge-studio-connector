<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

final class Claim
{
    public const CLAIMED     = 'claimed';
    public const UNCHANGED   = 'unchanged';
    public const IN_PROGRESS = 'in_progress';

    /**
     * Build the outcome of claiming a design for one export.
     *
     * @param string   $state   One of the constants
     * @param int|null $emailId Email the design is linked to, if any
     */
    public function __construct(
        public readonly string $state,
        public readonly ?int $emailId,
    ) {
    }

    /**
     * Whether this export may go on and change Mautic.
     *
     * @return bool
     */
    public function isClaimed(): bool
    {
        return self::CLAIMED === $this->state;
    }
}
