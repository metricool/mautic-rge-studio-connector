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
     * The first export of a design claims it, with no email yet.
     *
     * @return void
     */
    public function testFirstExportClaimsTheDesign(): void
    {
        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertTrue($claim->isClaimed());
        self::assertNull($claim->emailId);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('a'))['owned']);
    }

    /**
     * An identical export while the first is still running is told it is in progress.
     *
     * @return void
     */
    public function testIdenticalExportWhileTheFirstRunsIsInProgress(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now('+5 seconds'), 60);

        self::assertSame(Claim::IN_PROGRESS, $claim->state);
        self::assertFalse($claim->isClaimed());
    }

    /**
     * A claim older than the claim time no longer holds back an identical export.
     *
     * @return void
     */
    public function testStaleClaimIsTakenOver(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now('-120 seconds'), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
    }

    /**
     * A different export takes the design over, and the one it replaced no longer owns it.
     *
     * @return void
     */
    public function testNewerExportTakesTheDesignOver(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

        $claim = $this->store->claim(self::DESIGN, $this->hash('b'), $this->now(), 60);

        self::assertSame(Claim::CLAIMED, $claim->state);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('a'))['owned']);
        self::assertTrue($this->store->lockForCompletion(self::DESIGN, $this->hash('b'))['owned']);
    }

    /**
     * The export last applied is unchanged when sent again, and it stops an older export still running.
     *
     * @return void
     */
    public function testAppliedExportIsUnchangedAndStopsAnOlderOne(): void
    {
        $email = $this->email();
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), (int) $email->getId(), $this->now());

        $older = $this->store->claim(self::DESIGN, $this->hash('b'), $this->now(), 60);
        self::assertSame(Claim::CLAIMED, $older->state);
        self::assertSame($email->getId(), $older->emailId);

        $same = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);
        self::assertSame(Claim::UNCHANGED, $same->state);
        self::assertSame($email->getId(), $same->emailId);
        self::assertFalse($this->store->lockForCompletion(self::DESIGN, $this->hash('b'))['owned']);
    }

    /**
     * A released claim does not hold back the next export.
     *
     * @return void
     */
    public function testReleasedClaimDoesNotHoldBackTheNextExport(): void
    {
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);
        $this->store->release(self::DESIGN, $this->hash('a'));

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

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
        $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);
        $this->store->complete(self::DESIGN, $this->hash('a'), (int) $email->getId(), $this->now());
        $this->em->remove($email);
        $this->em->flush();

        $claim = $this->store->claim(self::DESIGN, $this->hash('a'), $this->now(), 60);

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
