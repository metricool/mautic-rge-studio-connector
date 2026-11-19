<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional\Import;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\RgeStudioConnectorBundle\Import\Claim;
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
