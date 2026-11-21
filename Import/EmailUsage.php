<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\FormBundle\Entity\Action;
use Mautic\PointBundle\Entity\TriggerEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\ErrorHandler\ErrorHandler;

class EmailUsage
{
    /**
     * Build the usage checks.
     *
     * @param EntityManagerInterface $entityManager Entity manager for table names and the connection
     * @param LoggerInterface        $logger        Mautic logger
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether an export may still change this email in place.
     *
     * Only an unpublished, never sent, never queued, standalone email may change. The raw publish
     * flag is read, because Mautic reports an email scheduled for later as not published. Run it
     * inside a transaction so the reads come from the primary database.
     *
     * @param Email $email Email linked to the design
     *
     * @return bool
     */
    public function isChangeable(Email $email): bool
    {
        if ($email->isPublished(false) || $email->getSentCount() > 0 || $email->getVariantSentCount() > 0) {
            return false;
        }

        if (null !== $email->getVariantParent() || null !== $email->getTranslationParent()) {
            return false;
        }

        if (!$email->getVariantChildren()->isEmpty() || !($email->getTranslationChildren()?->isEmpty() ?? true)) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $statTable  = $this->table(Stat::class);
        if (false !== $connection->fetchOne('SELECT 1 FROM '.$statTable.' WHERE email_id = ? LIMIT 1', [$email->getId()])) {
            return false;
        }

        $queueTable = $this->table(MessageQueue::class);

        return false === $connection->fetchOne(
            'SELECT 1 FROM '.$queueTable.' WHERE channel = ? AND channel_id = ? LIMIT 1',
            ['email', $email->getId()]
        );
    }

    /**
     * Whether a campaign, form or point action sends this email.
     *
     * Such an email must stay a campaign email, because a segment email goes only once to each contact.
     *
     * @param int $emailId Email id
     *
     * @return bool
     */
    public function isUsedByActions(int $emailId): bool
    {
        $connection = $this->entityManager->getConnection();
        $events     = $this->table(Event::class);

        $byChannel = $connection->fetchOne(
            'SELECT 1 FROM '.$events.' WHERE channel = ? AND channel_id = ? AND deleted IS NULL LIMIT 1',
            ['email', (string) $emailId]
        );
        if (false !== $byChannel) {
            return true;
        }

        $sources = [
            ['SELECT properties FROM '.$events.' WHERE type IN (?) AND deleted IS NULL', ['email.send', 'email.send.to.user']],
            ['SELECT properties FROM '.$this->table(Action::class).' WHERE type IN (?)', ['email.send.lead', 'email.send.user']],
            ['SELECT properties FROM '.$this->table(TriggerEvent::class).' WHERE type IN (?)', ['email.send', 'email.send_to_user']],
        ];

        foreach ($sources as [$sql, $types]) {
            $rows = $connection->fetchFirstColumn($sql, [$types], [ArrayParameterType::STRING]);
            foreach ($rows as $properties) {
                if ($this->propertiesPointTo($properties, $emailId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether serialized action properties point to the email.
     *
     * @param mixed $properties Serialized properties column
     * @param int   $emailId    Email id
     *
     * @return bool
     */
    private function propertiesPointTo(mixed $properties, int $emailId): bool
    {
        $data = $this->readProperties($properties);
        if (null === $data) {
            return false;
        }

        $userEmail  = $data['useremail'] ?? null;
        $candidates = [$data['email'] ?? null, is_array($userEmail) ? $userEmail['email'] ?? null : null];
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && (string) $candidate === (string) $emailId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Action properties as a list, or null when they are empty, unreadable or not a list.
     *
     * The PHP error of unreadable properties is logged.
     *
     * @param mixed $properties Serialized properties column
     *
     * @return array<mixed>|null
     */
    private function readProperties(mixed $properties): ?array
    {
        if (!is_string($properties) || '' === $properties) {
            return null;
        }

        try {
            $data = ErrorHandler::call(static fn (): mixed => unserialize($properties, ['allowed_classes' => false]));
        } catch (\ErrorException $exception) {
            $this->logger->warning('RGE Studio connector: the properties of a form action could not be read.', ['error' => $exception->getMessage()]);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Table name with Mautic's prefix.
     *
     * @param class-string $entity Entity class
     *
     * @return string
     */
    private function table(string $entity): string
    {
        return $this->entityManager->getClassMetadata($entity)->getTableName();
    }
}
