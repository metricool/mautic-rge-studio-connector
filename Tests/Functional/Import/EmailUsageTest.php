<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional\Import;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PointBundle\Entity\Trigger;
use Mautic\PointBundle\Entity\TriggerEvent;
use MauticPlugin\RgeStudioConnectorBundle\Import\EmailUsage;
use PHPUnit\Framework\Attributes\DataProvider;

final class EmailUsageTest extends MauticMysqlTestCase
{
    private EmailUsage $usage;

    /**
     * Boot Mautic and take the usage checks from the container.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $usage = static::getContainer()->get(EmailUsage::class);
        self::assertInstanceOf(EmailUsage::class, $usage);
        $this->usage = $usage;
    }

    /**
     * A new unpublished email that nothing uses may change.
     *
     * @return void
     */
    public function testNewUnpublishedEmailMayChange(): void
    {
        $email = $this->email();

        self::assertTrue($this->usage->isChangeable($email));
        self::assertFalse($this->usage->isUsedByActions((int) $email->getId()));
    }

    /**
     * An email published, scheduled or sent may not change.
     *
     * @param string $state How the email was used
     *
     * @return void
     */
    #[DataProvider('usedStates')]
    public function testPublishedOrSentEmailMayNotChange(string $state): void
    {
        $email = $this->email();
        if ('published' === $state || 'scheduled' === $state) {
            $email->setIsPublished(true);
        }
        if ('scheduled' === $state) {
            $email->setPublishUp(new \DateTime('+1 day'));
        }
        if ('sent' === $state) {
            $email->setSentCount(1);
        }
        if ('variant sent' === $state) {
            $email->setVariantSentCount(1);
        }
        $this->em->persist($email);
        $this->em->flush();

        self::assertFalse($this->usage->isChangeable($email));
    }

    /**
     * Published now, published with a send time tomorrow, sent, and sent as an A/B test.
     *
     * @return iterable<string, array{string}>
     */
    public static function usedStates(): iterable
    {
        yield 'published' => ['published'];
        yield 'scheduled' => ['scheduled'];
        yield 'sent' => ['sent'];
        yield 'variant sent' => ['variant sent'];
    }

    /**
     * Emails in an A/B test or a translation may not change, on either side.
     *
     * @return void
     */
    public function testVariantsAndTranslationsMayNotChange(): void
    {
        $parent  = $this->email();
        $variant = $this->email();
        $parent->addVariantChild($variant);
        $variant->setVariantParent($parent);

        $original    = $this->email();
        $translation = $this->email();
        $original->addTranslationChild($translation);
        $translation->setTranslationParent($original);
        $this->em->flush();

        self::assertFalse($this->usage->isChangeable($parent));
        self::assertFalse($this->usage->isChangeable($variant));
        self::assertFalse($this->usage->isChangeable($original));
        self::assertFalse($this->usage->isChangeable($translation));
    }

    /**
     * An email with a send record or a queued message may not change.
     *
     * @return void
     */
    public function testEmailWithSendRecordsOrQueuedMessagesMayNotChange(): void
    {
        $sent    = $this->email();
        $queued  = $this->email();
        $contact = new Lead();
        $contact->setEmail('contact@example.com');
        $this->em->persist($contact);

        $stat = new Stat();
        $stat->setEmail($sent);
        $stat->setEmailAddress('contact@example.com');
        $stat->setDateSent(new \DateTime());
        $stat->setTrackingHash('rge-studio-usage-test');
        $this->em->persist($stat);

        $message = new MessageQueue();
        $message->setChannel('email');
        $message->setChannelId((int) $queued->getId());
        $message->setLead($contact);
        $message->setPriority(MessageQueue::PRIORITY_NORMAL);
        $message->setMaxAttempts(3);
        $message->setAttempts(0);
        $message->setStatus(MessageQueue::STATUS_PENDING);
        $message->setDatePublished(new \DateTime());
        $message->setScheduledDate(new \DateTime());
        $this->em->persist($message);
        $this->em->flush();

        self::assertFalse($this->usage->isChangeable($sent));
        self::assertFalse($this->usage->isChangeable($queued));
    }

    /**
     * A campaign step that sends the email is found by its channel and by its properties.
     *
     * @return void
     */
    public function testCampaignStepsThatSendTheEmailAreFound(): void
    {
        $byChannel = $this->email();
        $event     = $this->campaignEvent('email.send', []);
        $event->setChannel('email');
        $event->setChannelId($byChannel->getId());

        $toUser = $this->email();
        $this->campaignEvent('email.send.to.user', ['useremail' => ['email' => $toUser->getId()], 'user_id' => [1]]);
        $this->em->flush();

        self::assertTrue($this->usage->isUsedByActions((int) $byChannel->getId()));
        self::assertTrue($this->usage->isUsedByActions((int) $toUser->getId()));
    }

    /**
     * A deleted campaign step no longer counts.
     *
     * @return void
     */
    public function testDeletedCampaignStepIsIgnored(): void
    {
        $email = $this->email();
        $event = $this->campaignEvent('email.send', ['email' => $email->getId()]);
        $event->setChannel('email');
        $event->setChannelId($email->getId());
        $event->setDeleted();
        $this->em->flush();

        self::assertFalse($this->usage->isUsedByActions((int) $email->getId()));
    }

    /**
     * Form actions that send the email to the contact or to a user are found.
     *
     * @return void
     */
    public function testFormActionsThatSendTheEmailAreFound(): void
    {
        $toContact = $this->email();
        $toUser    = $this->email();
        $form      = new Form();
        $form->setName('RGE Studio usage test');
        $form->setAlias('rgeusage');
        $this->em->persist($form);

        $this->formAction($form, 'email.send.lead', ['email' => $toContact->getId()]);
        $this->formAction($form, 'email.send.user', ['useremail' => ['email' => $toUser->getId()], 'user_id' => [1]]);
        $this->em->flush();

        self::assertTrue($this->usage->isUsedByActions((int) $toContact->getId()));
        self::assertTrue($this->usage->isUsedByActions((int) $toUser->getId()));
    }

    /**
     * Point trigger events that send the email to the contact or to a user are found.
     *
     * @return void
     */
    public function testPointTriggersThatSendTheEmailAreFound(): void
    {
        $toContact = $this->email();
        $toUser    = $this->email();
        $trigger   = new Trigger();
        $trigger->setName('RGE Studio usage test');
        $this->em->persist($trigger);

        $this->triggerEvent($trigger, 'email.send', ['email' => $toContact->getId()]);
        $this->triggerEvent($trigger, 'email.send_to_user', ['useremail' => ['email' => $toUser->getId()], 'user_id' => [1]]);
        $this->em->flush();

        self::assertTrue($this->usage->isUsedByActions((int) $toContact->getId()));
        self::assertTrue($this->usage->isUsedByActions((int) $toUser->getId()));
    }

    /**
     * Actions that send another email do not count.
     *
     * @return void
     */
    public function testActionsForAnotherEmailAreIgnored(): void
    {
        $email = $this->email();
        $other = $this->email();
        $event = $this->campaignEvent('email.send', ['email' => $other->getId()]);
        $event->setChannel('email');
        $event->setChannelId($other->getId());
        $this->em->flush();

        self::assertFalse($this->usage->isUsedByActions((int) $email->getId()));
    }

    /**
     * Saved unpublished email.
     *
     * @return Email
     */
    private function email(): Email
    {
        $email = new Email();
        $email->setName('Usage email');
        $email->setSubject('Usage email');
        $email->setEmailType('template');
        $email->setIsPublished(false);
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    /**
     * Campaign action step of a new campaign, not flushed yet.
     *
     * @param string               $type       Campaign event type
     * @param array<string, mixed> $properties Step properties
     *
     * @return Event
     */
    private function campaignEvent(string $type, array $properties): Event
    {
        $campaign = new Campaign();
        $campaign->setName('RGE Studio usage test');
        $this->em->persist($campaign);

        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName('Send email');
        $event->setType($type);
        $event->setEventType(Event::TYPE_ACTION);
        $event->setTriggerMode('immediate');
        $event->setProperties($properties);
        $this->em->persist($event);

        return $event;
    }

    /**
     * Form action, not flushed yet.
     *
     * @param Form                 $form       Form the action belongs to
     * @param string               $type       Form action type
     * @param array<string, mixed> $properties Action properties
     *
     * @return void
     */
    private function formAction(Form $form, string $type, array $properties): void
    {
        $action = new Action();
        $action->setForm($form);
        $action->setName('Send email');
        $action->setType($type);
        $action->setProperties($properties);
        $this->em->persist($action);
    }

    /**
     * Point trigger event, not flushed yet.
     *
     * @param Trigger              $trigger    Trigger the event belongs to
     * @param string               $type       Trigger event type
     * @param array<string, mixed> $properties Event properties
     *
     * @return void
     */
    private function triggerEvent(Trigger $trigger, string $type, array $properties): void
    {
        $event = new TriggerEvent();
        $event->setTrigger($trigger);
        $event->setName('Send email');
        $event->setType($type);
        $event->setProperties($properties);
        $this->em->persist($event);
    }
}
