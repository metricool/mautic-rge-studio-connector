<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\RgeStudioConnectorBundle\Entity\DesignLink;
use MauticPlugin\RgeStudioConnectorBundle\Import\Claim;
use MauticPlugin\RgeStudioConnectorBundle\Import\ConnectorConfigurationException;
use MauticPlugin\RgeStudioConnectorBundle\Import\DesignLinkStore;

final class DesignLinkStoreTest extends MauticMysqlTestCase
{
    private const DESIGN = '1234567';

    private DesignLinkStore $store;

    /**
     * Boot Mautic and take the store from the container.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->store = static::getContainer()->get(DesignLinkStore::class);
    }

    /**
     * The check passes while the table exists, and names the table when it is missing.
     *
     * @return void
     */
    public function testCheckFindsAMissingTable(): void
    {
        $this->store->check();

        $metadata = new ClassMetadata(DesignLink::class);
        $metadata->setPrimaryTable(['name' => 'rge_studio_designs_missing']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($this->em->getConnection());
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $this->expectException(ConnectorConfigurationException::class);
        $this->expectExceptionMessage('The plugin table rge_studio_designs_missing is missing. Reload the plugins.');
        (new DesignLinkStore($entityManager))->check();
    }

    /**
     * The first export of a design claims it, with no email yet, and only that claim owns it.
     *
     * @return void
     */
    public function testFirstExportClaimsTheDesign(): void
    {
        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertTrue($claim->isClaimed());
        self::assertNull($claim->emailId);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 101)['owned']);
    }

    /**
     * An identical export while the first is still running is told it is in progress.
     *
     * @return void
     */
    public function testIdenticalExportWhileTheFirstRunsIsInProgress(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now('+5 seconds'), 60);

        self::assertSame(Claim::IN_PROGRESS, $claim->state);
        self::assertFalse($claim->isClaimed());
    }

    /**
     * A claim older than the claim time no longer holds back an identical export, which then owns the design.
     *
     * @return void
     */
    public function testStaleClaimIsTakenOver(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now('-120 seconds'), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 200)['owned']);
    }

    /**
     * A claim exactly as old as the claim time is already stale, one second younger is not.
     *
     * @return void
     */
    public function testClaimAtTheClaimTimeIsStale(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $now->modify('-60 seconds'), 60);
        $this->store->claim('7654321', $this->hash('a'), 100, $now->modify('-59 seconds'), 60);

        self::assertSame(Claim::CLAIMED, $this->store->claim(self::DESIGN, $this->hash('a'), 200, $now, 60)->state);
        self::assertSame(Claim::IN_PROGRESS, $this->store->claim('7654321', $this->hash('a'), 200, $now, 60)->state);
    }

    /**
     * A newer, different export takes the design over, and the one it replaced no longer owns it.
     *
     * @return void
     */
    public function testNewerExportTakesTheDesignOver(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('b'), 200)['owned']);
    }

    /**
     * A claim on another connection waits while the design is locked for completion. Once the lock is released,
     * the newer export takes the design over from the one that held it.
     *
     * @return void
     */
    public function testClaimWaitsForACompletionOnAnotherConnection(): void
    {
        $holding  = $this->separateConnection();
        $claiming = $this->separateConnection();
        $claiming->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        $first  = new DesignLinkStore($this->managerOver($holding));
        $second = new DesignLinkStore($this->managerOver($claiming));
        $design = '7777777';

        try {
            $first->claim($design, $this->hash('a'), 100, $this->now(), 60);
            $holding->beginTransaction();
            self::assertTrue($first->lockForCompletion($design, $this->hash('a'), 100)['owned']);

            try {
                $second->claim($design, $this->hash('b'), 200, $this->now(), 60);
                self::fail('The claim did not wait for the lock.');
            } catch (LockWaitTimeoutException) {
            }

            $holding->rollBack();
            self::assertSame(Claim::CLAIMED, $second->claim($design, $this->hash('b'), 200, $this->now(), 60)->state);
            self::assertFalse($first->lockForCompletion($design, $this->hash('a'), 100)['owned']);
        } finally {
            if ($holding->isTransactionActive()) {
                $holding->rollBack();
            }
            $claiming->delete($this->em->getClassMetadata(DesignLink::class)->getTableName(), ['design_id' => $design]);
            $holding->close();
            $claiming->close();
        }
    }

    /**
     * An export that arrived before one still running is superseded, and the newer one keeps the design.
     *
     * @return void
     */
    public function testOlderExportIsSupersededByANewerOneRunning(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);

        self::assertSame(Claim::SUPERSEDED, $claim->state);
        self::assertFalse($claim->isClaimed());
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('b'), 200)['owned']);
    }

    /**
     * An export that arrived before the one already applied is superseded and never overwrites it.
     *
     * @return void
     */
    public function testOlderExportIsSupersededByAnAppliedNewerOne(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('b'), 200, (int) $email->getId(), $this->now());

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);

        self::assertSame(Claim::SUPERSEDED, $claim->state);
        self::assertSame($email->getId(), $claim->emailId);
    }

    /**
     * An identical export waiting for one still running gives way when a newer export is applied meanwhile, and the
     * export it waited for can no longer complete.
     *
     * @return void
     */
    public function testWaitingIdenticalExportGivesWayToANewerOne(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        self::assertSame(Claim::IN_PROGRESS, $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60)->state);

        $this->store->claim(self::DESIGN, $this->hash('b'), 300, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('b'), 300, (int) $email->getId(), $this->now());

        self::assertSame(Claim::SUPERSEDED, $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60)->state);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
    }

    /**
     * An identical export told to wait records its arrival, so a different export that arrived before it is
     * superseded and the export running for both keeps the design.
     *
     * @return void
     */
    public function testWaitingIdenticalExportStopsAnOlderOne(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        self::assertSame(Claim::IN_PROGRESS, $this->store->claim(self::DESIGN, $this->hash('a'), 300, $this->now(), 60)->state);

        self::assertSame(Claim::SUPERSEDED, $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60)->state);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
    }

    /**
     * Two claims with the same content are told apart: an older one can neither complete nor release the newer one.
     *
     * @return void
     */
    public function testEachClaimOnlyCompletesAndReleasesItself(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60);
        self::assertSame(Claim::CLAIMED, $this->store->claim(self::DESIGN, $this->hash('a'), 300, $this->now(), 60)->state);

        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
        $this->store->release(self::DESIGN, $this->hash('a'), 100);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now());

        $lock = $this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 300);
        self::assertTrue($lock['owned']);
        self::assertNull($lock['emailId']);
    }

    /**
     * The export last applied is unchanged when sent again, it stops an older export still running, and it counts as
     * the newest export received.
     *
     * @return void
     */
    public function testAppliedExportIsUnchangedAndStopsAnOlderOne(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now());

        $older = $this->store->claim(self::DESIGN, $this->hash('b'), 200, $this->now(), 60);
        self::assertSame(Claim::CLAIMED, $older->state);
        self::assertSame($email->getId(), $older->emailId);

        $same = $this->store->claim(self::DESIGN, $this->hash('a'), 300, $this->now(), 60);
        self::assertSame(Claim::UNCHANGED, $same->state);
        self::assertSame($email->getId(), $same->emailId);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('b'), 200)['owned']);
        self::assertSame(Claim::SUPERSEDED, $this->store->claim(self::DESIGN, $this->hash('c'), 250, $this->now(), 60)->state);
    }

    /**
     * An export saved with images not copied is not recorded as applied, so the same export sent again claims the
     * design for its email.
     *
     * @return void
     */
    public function testExportWithImagesNotCopiedIsNotRecordedAsApplied(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now(), false);

        $again = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $again->state);
        self::assertSame($email->getId(), $again->emailId);
        self::assertSame(Claim::SUPERSEDED, $this->store->claim(self::DESIGN, $this->hash('b'), 150, $this->now(), 60)->state);
    }

    /**
     * A copy of the applied export that arrived before a newer, different export does not stop that newer export.
     *
     * @return void
     */
    public function testEarlierCopyOfTheAppliedExportLeavesANewerOneRunning(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now());
        $this->store->claim(self::DESIGN, $this->hash('b'), 300, $this->now(), 60);

        $same = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60);

        self::assertSame(Claim::UNCHANGED, $same->state);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('b'), 300)['owned']);
    }

    /**
     * A copy of the applied export that arrived before an identical export told to wait does not stop the export
     * running for both, while a copy newer than every export received does.
     *
     * @return void
     */
    public function testCopyOfTheAppliedExportOlderThanAWaitingOneLeavesTheRunningOne(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('x'), 50, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('x'), 50, (int) $email->getId(), $this->now());
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        self::assertSame(Claim::IN_PROGRESS, $this->store->claim(self::DESIGN, $this->hash('a'), 300, $this->now(), 60)->state);

        self::assertSame(Claim::UNCHANGED, $this->store->claim(self::DESIGN, $this->hash('x'), 200, $this->now(), 60)->state);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);

        self::assertSame(Claim::UNCHANGED, $this->store->claim(self::DESIGN, $this->hash('x'), 400, $this->now(), 60)->state);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100)['owned']);
    }

    /**
     * Exports that arrive in the same microsecond are told apart by the order they claim: a different export takes
     * over, and a copy of the applied export does not stop it.
     *
     * @return void
     */
    public function testExportsArrivingTogetherKeepTheClaimOrder(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now());

        self::assertSame(Claim::CLAIMED, $this->store->claim(self::DESIGN, $this->hash('b'), 300, $this->now(), 60)->state);
        self::assertSame(Claim::CLAIMED, $this->store->claim(self::DESIGN, $this->hash('c'), 300, $this->now(), 60)->state);
        self::assertSame(Claim::UNCHANGED, $this->store->claim(self::DESIGN, $this->hash('a'), 300, $this->now(), 60)->state);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('c'), 300)['owned']);
    }

    /**
     * A released claim does not hold back the next export.
     *
     * @return void
     */
    public function testReleasedClaimDoesNotHoldBackTheNextExport(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->release(self::DESIGN, $this->hash('a'), 100);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
    }

    /**
     * A design that has no row is not owned by any export and has no email.
     *
     * @return void
     */
    public function testDesignWithoutARowIsNotOwned(): void
    {
        self::assertSame(['owned' => false, 'emailId' => null], $this->store->lockForCompletion(self::DESIGN, $this->hash('a'), 100));
    }

    /**
     * A claim with no claim time holds nothing back, so an identical export takes it over.
     *
     * @return void
     */
    public function testClaimWithoutAClaimTimeIsStale(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $table = $this->em->getClassMetadata(DesignLink::class)->getTableName();
        $this->em->getConnection()->executeStatement('UPDATE '.$table.' SET claimed_at = NULL WHERE design_id = ?', [self::DESIGN]);

        self::assertSame(Claim::CLAIMED, $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60)->state);
    }

    /**
     * An email deleted in Mautic is forgotten, so the next export creates a new one.
     *
     * @return void
     */
    public function testDeletedEmailIsNotReused(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), 100, $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), 100, (int) $email->getId(), $this->now());
        $this->em->remove($email);
        $this->em->flush();

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), 200, $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertNull($claim->emailId);
    }

    /**
     * Saved email for a design to point to.
     *
     * @return Email
     */
    private function email(): Email
    {
        $email = new Email();
        $email->setName('Design email');
        $email->setSubject('Design email');
        $email->setEmailType('template');
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    /**
     * New connection to the test database, outside the transaction each test runs in.
     *
     * @return Connection
     */
    private function separateConnection(): Connection
    {
        return DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    /**
     * Entity manager over another connection, with the mappings the store reads.
     *
     * @param Connection $connection Connection the store uses
     *
     * @return EntityManagerInterface
     */
    private function managerOver(Connection $connection): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [DesignLink::class, $this->em->getClassMetadata(DesignLink::class)],
            [Email::class, $this->em->getClassMetadata(Email::class)],
        ]);

        return $entityManager;
    }

    /**
     * Export hash built from a short seed.
     *
     * @param string $seed Text that tells exports apart
     *
     * @return string
     */
    private function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    /**
     * Current time in UTC, moved when asked.
     *
     * @param string $modify Relative time understood by PHP
     *
     * @return \DateTimeImmutable
     */
    private function now(string $modify = 'now'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($modify, new \DateTimeZone('UTC'));
    }
}
