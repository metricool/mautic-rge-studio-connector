<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\RgeStudioConnectorBundle\Entity\DesignLink;
use MauticPlugin\RgeStudioConnectorBundle\Import\Claim;
use MauticPlugin\RgeStudioConnectorBundle\Import\DesignLinkStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DesignLinkStoreFailureTest extends TestCase
{
    /**
     * A deadlock is tried again after a pause that grows, and the third one is passed on.
     *
     * @return void
     */
    public function testDeadlocksAreTriedThreeTimes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))->method('insert')->willThrowException($this->deadlock());

        $start = microtime(true);
        try {
            $this->store($connection)->claim('1234567', 'hash', 100, new \DateTimeImmutable(), 60);
            self::fail('The deadlock was not passed on.');
        } catch (DeadlockException) {
        }

        self::assertGreaterThanOrEqual(0.3, microtime(true) - $start);
    }

    /**
     * A claim that meets one deadlock succeeds on the next attempt.
     *
     * @return void
     */
    public function testDeadlockThenSuccessClaims(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls      = 0;
        $connection->method('insert')->willReturnCallback(function () use (&$calls): int {
            if (1 === ++$calls) {
                throw $this->deadlock();
            }

            return 1;
        });

        $claim = $this->store($connection)->claim('1234567', 'hash', 100, new \DateTimeImmutable(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertSame(2, $calls);
    }

    /**
     * A row that is gone between the failed insert and the lock is claimed again from the start.
     *
     * @return void
     */
    public function testRowGoneAfterAConflictIsClaimedAgain(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls      = 0;
        $connection->method('insert')->willReturnCallback(function () use (&$calls): int {
            if (1 === ++$calls) {
                throw new UniqueConstraintViolationException($this->driverException(), null);
            }

            return 1;
        });
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(false);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $claim = $this->store($connection)->claim('1234567', 'hash', 100, new \DateTimeImmutable(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertSame(2, $calls);
    }

    /**
     * A failure while the existing row is locked rolls back the transaction still open and is passed on.
     *
     * @dataProvider openTransactions
     *
     * @param bool $active    Whether the transaction is still open when the failure arrives
     * @param int  $rollbacks Rollbacks expected
     *
     * @return void
     */
    #[DataProvider('openTransactions')]
    public function testFailureWhileLockedIsRolledBackAndPassedOn(bool $active, int $rollbacks): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new UniqueConstraintViolationException($this->driverException(), null));
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('Lost connection.'));
        $connection->method('isTransactionActive')->willReturn($active);
        $connection->expects(self::exactly($rollbacks))->method('rollBack');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lost connection.');
        $this->store($connection)->claim('1234567', 'hash', 100, new \DateTimeImmutable(), 60);
    }

    /**
     * Whether the transaction is open, and the rollbacks it needs.
     *
     * @return iterable<string, array{bool, int}>
     */
    public static function openTransactions(): iterable
    {
        yield 'transaction still open' => [true, 1];
        yield 'transaction already closed' => [false, 0];
    }

    /**
     * With a read replica configured, the claim reads from the primary database.
     *
     * @return void
     */
    public function testReplicaConnectionReadsFromThePrimary(): void
    {
        $connection = $this->createMock(PrimaryReadReplicaConnection::class);
        $connection->expects(self::once())->method('ensureConnectedToPrimary');
        $connection->method('insert')->willReturn(1);

        $claim = $this->store($connection)->claim('1234567', 'hash', 100, new \DateTimeImmutable(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
    }

    /**
     * Store over the given connection.
     *
     * @param Connection $connection Connection the store uses
     *
     * @return DesignLinkStore
     */
    private function store(Connection $connection): DesignLinkStore
    {
        $metadata = new ClassMetadata(DesignLink::class);
        $metadata->setPrimaryTable(['name' => 'rge_studio_designs']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return new DesignLinkStore($entityManager);
    }

    /**
     * Deadlock as the database driver reports it.
     *
     * @return DeadlockException
     */
    private function deadlock(): DeadlockException
    {
        return new DeadlockException($this->driverException(), null);
    }

    /**
     * Driver error the DBAL exceptions wrap.
     *
     * @return DriverException
     */
    private function driverException(): DriverException
    {
        return new class('Database error.') extends \Exception implements DriverException {
            /**
             * SQL state of the error.
             *
             * @return string|null
             */
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }
}
