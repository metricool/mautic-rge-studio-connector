<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class DesignLink
{
    public const TABLE = 'rge_studio_designs';

    private ?int $id = null;

    private string $designId = '';

    private ?int $emailId = null;

    private ?string $payloadHash = null;

    private ?string $pendingHash = null;

    private ?string $pendingArrival = null;

    private ?string $lastArrival = null;

    private ?\DateTimeImmutable $claimedAt = null;

    private ?\DateTimeImmutable $dateModified = null;

    /**
     * Describe the table that links each RGE Studio design to its Mautic email.
     *
     * The plugin reads and writes the table through DBAL. This mapping only lets Mautic create it on install.
     *
     * @param ORM\ClassMetadata<self> $metadata Doctrine metadata to fill
     *
     * @return void
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable(self::TABLE);
        $builder->addUniqueConstraint(['design_id'], 'rge_studio_design_unique');
        $builder->addId();
        $builder->createField('designId', Types::STRING)->columnName('design_id')->length(64)->build();
        $builder->createField('emailId', Types::INTEGER)->columnName('email_id')->nullable()->build();
        $builder->createField('payloadHash', Types::STRING)->columnName('payload_hash')->length(64)->nullable()->build();
        $builder->createField('pendingHash', Types::STRING)->columnName('pending_hash')->length(64)->nullable()->build();
        $builder->createField('pendingArrival', Types::BIGINT)->columnName('pending_arrival')->nullable()->build();
        $builder->createField('lastArrival', Types::BIGINT)->columnName('last_arrival')->nullable()->build();
        $builder->createField('claimedAt', Types::DATETIME_IMMUTABLE)->columnName('claimed_at')->nullable()->build();
        $builder->createField('dateModified', Types::DATETIME_IMMUTABLE)->columnName('date_modified')->nullable()->build();
    }

    /**
     * Row id.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Design id in RGE Studio.
     *
     * @return string
     */
    public function getDesignId(): string
    {
        return $this->designId;
    }

    /**
     * Id of the Mautic email the design last updated or created.
     *
     * @return int|null
     */
    public function getEmailId(): ?int
    {
        return $this->emailId;
    }

    /**
     * Hash of the last export applied.
     *
     * @return string|null
     */
    public function getPayloadHash(): ?string
    {
        return $this->payloadHash;
    }

    /**
     * Hash of the export being applied now.
     *
     * @return string|null
     */
    public function getPendingHash(): ?string
    {
        return $this->pendingHash;
    }

    /**
     * When the request of the export being applied now arrived, in microseconds since 1970.
     *
     * @return string|null
     */
    public function getPendingArrival(): ?string
    {
        return $this->pendingArrival;
    }

    /**
     * When the request of the newest export received for the design arrived, in microseconds since 1970.
     *
     * @return string|null
     */
    public function getLastArrival(): ?string
    {
        return $this->lastArrival;
    }

    /**
     * When the export being applied now was claimed.
     *
     * @return \DateTimeImmutable|null
     */
    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    /**
     * When the row last changed.
     *
     * @return \DateTimeImmutable|null
     */
    public function getDateModified(): ?\DateTimeImmutable
    {
        return $this->dateModified;
    }
}
