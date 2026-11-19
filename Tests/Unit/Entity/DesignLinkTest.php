<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\RgeStudioConnectorBundle\Entity\DesignLink;
use PHPUnit\Framework\TestCase;

final class DesignLinkTest extends TestCase
{
    /**
     * The table Mautic creates on install has one row per design, and room for the ids and hashes the plugin writes.
     *
     * @return void
     */
    public function testTableMapping(): void
    {
        $metadata = new ClassMetadata(DesignLink::class);
        DesignLink::loadMetadata($metadata);

        self::assertSame(DesignLink::TABLE, $metadata->getTableName());
        self::assertSame(['rge_studio_design_unique' => ['columns' => ['design_id']]], $metadata->table['uniqueConstraints'] ?? null);
        self::assertSame(['id'], $metadata->getIdentifierFieldNames());

        $expected = [
            'designId'     => ['design_id', 'string', 64, false],
            'emailId'      => ['email_id', 'integer', null, true],
            'payloadHash'  => ['payload_hash', 'string', 64, true],
            'pendingHash'    => ['pending_hash', 'string', 64, true],
            'pendingArrival' => ['pending_arrival', 'bigint', null, true],
            'lastArrival'    => ['last_arrival', 'bigint', null, true],
            'claimedAt'      => ['claimed_at', 'datetime_immutable', null, true],
            'dateModified'   => ['date_modified', 'datetime_immutable', null, true],
        ];
        foreach ($expected as $field => [$column, $type, $length, $nullable]) {
            $mapping = $metadata->getFieldMapping($field);
            self::assertSame(
                [$column, $type, $length, $nullable],
                [$mapping['columnName'], $mapping['type'], $mapping['length'] ?? null, $mapping['nullable'] ?? false],
                $field
            );
        }
    }

    /**
     * A new link is empty: no row id, no design, no email and no hashes or times yet.
     *
     * @return void
     */
    public function testNewLinkIsEmpty(): void
    {
        $link = new DesignLink();

        self::assertNull($link->getId());
        self::assertSame('', $link->getDesignId());
        self::assertNull($link->getEmailId());
        self::assertNull($link->getPayloadHash());
        self::assertNull($link->getPendingHash());
        self::assertNull($link->getPendingArrival());
        self::assertNull($link->getLastArrival());
        self::assertNull($link->getClaimedAt());
        self::assertNull($link->getDateModified());
    }
}
