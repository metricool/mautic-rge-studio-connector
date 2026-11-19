<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

final class ImportResult
{
    public const CREATED    = 'created';
    public const UPDATED    = 'updated';
    public const UNCHANGED  = 'unchanged';
    public const SUPERSEDED = 'superseded';

    /**
     * Build the outcome of one export.
     *
     * @param string   $state    One of the constants
     * @param int|null $emailId  Email created, updated or already linked
     * @param int      $problems Number of problems flagged on the email
     */
    public function __construct(
        public readonly string $state,
        public readonly ?int $emailId,
        public readonly int $problems = 0,
    ) {
    }

    /**
     * Answer body for the Custom Connector.
     *
     * @return array{result: string, emailId: int|null, problems: int}
     */
    public function toArray(): array
    {
        return ['result' => $this->state, 'emailId' => $this->emailId, 'problems' => $this->problems];
    }
}
