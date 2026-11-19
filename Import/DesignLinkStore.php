<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\RgeStudioConnectorBundle\Entity\DesignLink;

class DesignLinkStore
{
    private const ATTEMPTS = 3;

    /**
     * Build the store.
     *
     * @param EntityManagerInterface $entityManager Entity manager whose connection also saves the email
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Claim a design for one export, so two exports of one design never create two emails.
     *
     * An export identical to the last one applied changes nothing, and stops any older export of the design
     * still running from overwriting it. An export identical to one still being applied is reported as in progress.
     *
     * @param string             $designId     Design id
     * @param string             $hash         Hash of this export
     * @param \DateTimeImmutable $now          Current time
     * @param int                $claimSeconds Seconds after which an unfinished claim is ignored
     *
     * @return Claim
     */
    public function claim(string $designId, string $hash, \DateTimeImmutable $now, int $claimSeconds): Claim
    {
        $this->usePrimary();

        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->tryClaim($designId, $hash, $now, $claimSeconds);
            } catch (RetryableException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $exception;
                }
                usleep(100000 * $attempt);
            }
        }
    }

    /**
     * Lock the design's row inside the caller's transaction and tell whether this export still owns it.
     *
     * @param string $designId Design id
     * @param string $hash     Hash of this export
     *
     * @return array{owned: bool, emailId: int|null}
     */
    public function lockForCompletion(string $designId, string $hash): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT email_id, pending_hash FROM '.$this->table().' WHERE design_id = ? FOR UPDATE',
            [$designId]
        );

        if (false === $row) {
            return ['owned' => false, 'emailId' => null];
        }

        return [
            'owned'   => $hash === $row['pending_hash'],
            'emailId' => is_numeric($row['email_id']) ? (int) $row['email_id'] : null,
        ];
    }

    /**
     * Record that this export is applied to the email, inside the caller's transaction.
     *
     * @param string             $designId Design id
     * @param string             $hash     Hash of this export
     * @param int                $emailId  Email the export was saved to
     * @param \DateTimeImmutable $now      Current time
     *
     * @return void
     */
    public function complete(string $designId, string $hash, int $emailId, \DateTimeImmutable $now): void
    {
        $this->connection()->executeStatement(
            'UPDATE '.$this->table().' SET email_id = ?, payload_hash = ?, pending_hash = NULL, claimed_at = NULL, date_modified = ?'
            .' WHERE design_id = ? AND pending_hash = ?',
            [$emailId, $hash, $this->date($now), $designId, $hash]
        );
    }

    /**
     * Drop this export's claim after a failure, so the next export of the design is not held back.
     *
     * @param string $designId Design id
     * @param string $hash     Hash of this export
     *
     * @return void
     */
    public function release(string $designId, string $hash): void
    {
        $this->connection()->executeStatement(
            'UPDATE '.$this->table().' SET pending_hash = NULL, claimed_at = NULL WHERE design_id = ? AND pending_hash = ?',
            [$designId, $hash]
        );
    }

    /**
     * One claim attempt: insert the row, or lock the existing one in a new transaction.
     *
     * @param string             $designId     Design id
     * @param string             $hash         Hash of this export
     * @param \DateTimeImmutable $now          Current time
     * @param int                $claimSeconds Seconds after which an unfinished claim is ignored
     *
     * @return Claim
     */
    private function tryClaim(string $designId, string $hash, \DateTimeImmutable $now, int $claimSeconds): Claim
    {
        $connection = $this->connection();

        try {
            $connection->insert($this->table(), [
                'design_id'     => $designId,
                'pending_hash'  => $hash,
                'claimed_at'    => $this->date($now),
                'date_modified' => $this->date($now),
            ]);

            return new Claim(Claim::CLAIMED, null);
        } catch (UniqueConstraintViolationException) {
        }

        $connection->beginTransaction();
        try {
            $row = $connection->fetchAssociative(
                'SELECT email_id, payload_hash, pending_hash, claimed_at FROM '.$this->table().' WHERE design_id = ? FOR UPDATE',
                [$designId]
            );
            if (false === $row) {
                $connection->rollBack();

                return $this->tryClaim($designId, $hash, $now, $claimSeconds);
            }

            $emailId = $this->existingEmailId($row['email_id']);

            if (null !== $emailId && $hash === $row['payload_hash']) {
                if (null !== $row['pending_hash'] && $hash !== $row['pending_hash']) {
                    $connection->executeStatement(
                        'UPDATE '.$this->table().' SET pending_hash = NULL, claimed_at = NULL WHERE design_id = ?',
                        [$designId]
                    );
                }
                $connection->commit();

                return new Claim(Claim::UNCHANGED, $emailId);
            }

            if ($hash === $row['pending_hash'] && $this->isFresh($row['claimed_at'], $now, $claimSeconds)) {
                $connection->commit();

                return new Claim(Claim::IN_PROGRESS, $emailId);
            }

            $connection->executeStatement(
                'UPDATE '.$this->table().' SET pending_hash = ?, claimed_at = ?, date_modified = ? WHERE design_id = ?',
                [$hash, $this->date($now), $this->date($now), $designId]
            );
            $connection->commit();

            return new Claim(Claim::CLAIMED, $emailId);
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Email id from the row when that email still exists.
     *
     * @param mixed $emailId Value of the email_id column
     *
     * @return int|null
     */
    private function existingEmailId(mixed $emailId): ?int
    {
        if (!is_numeric($emailId)) {
            return null;
        }

        $id     = (int) $emailId;
        $table  = $this->entityManager->getClassMetadata(Email::class)->getTableName();
        $exists = $this->connection()->fetchOne('SELECT 1 FROM '.$table.' WHERE id = ?', [$id]);

        return false === $exists ? null : $id;
    }

    /**
     * Whether an unfinished claim is recent enough to hold back an identical export.
     *
     * @param mixed              $claimedAt    Value of the claimed_at column
     * @param \DateTimeImmutable $now          Current time
     * @param int                $claimSeconds Seconds a claim stays fresh
     *
     * @return bool
     */
    private function isFresh(mixed $claimedAt, \DateTimeImmutable $now, int $claimSeconds): bool
    {
        if (!is_string($claimedAt) || '' === $claimedAt) {
            return false;
        }

        $claimed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $claimedAt, new \DateTimeZone('UTC'));

        return false !== $claimed && $now->getTimestamp() - $claimed->getTimestamp() < $claimSeconds;
    }

    /**
     * Send this request's reads to the primary database when a read replica is configured.
     *
     * @return void
     */
    private function usePrimary(): void
    {
        $connection = $this->connection();
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }
    }

    /**
     * Database time as stored, in UTC.
     *
     * @param \DateTimeImmutable $date Time to format
     *
     * @return string
     */
    private function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Table name with Mautic's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->entityManager->getClassMetadata(DesignLink::class)->getTableName();
    }

    /**
     * Connection shared with the entity manager.
     *
     * @return Connection
     */
    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
