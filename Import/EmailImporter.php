<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExport;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;

class EmailImporter
{
    public const SEGMENT_EMAIL  = 'list';
    public const CAMPAIGN_EMAIL = 'template';

    private const NAME_LIMIT    = 190;
    private const SUBJECT_LIMIT = 190;

    /**
     * Build the importer.
     *
     * @param ConnectorSettings      $settings      Plugin settings
     * @param DesignLinkStore        $store         Design to email links
     * @param ImageImporter          $images        Image copier
     * @param UnsubscribeLinkChecker $unsubscribe   Unsubscribe link checks
     * @param EmailUsage             $usage         Checks on how an email is used
     * @param EmailModel             $emailModel    Mautic email model
     * @param ImportProject          $project       Import project, where this Mautic has projects
     * @param UserModel              $userModel     Mautic user model
     * @param ActAsUser              $actAs         Runs a save as the import user
     * @param LinkChecker            $links         Link address check
     * @param EntityManagerInterface $entityManager Entity manager
     */
    public function __construct(
        private ConnectorSettings $settings,
        private DesignLinkStore $store,
        private ImageImporter $images,
        private UnsubscribeLinkChecker $unsubscribe,
        private EmailUsage $usage,
        private EmailModel $emailModel,
        private ImportProject $project,
        private UserModel $userModel,
        private ActAsUser $actAs,
        private LinkChecker $links,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Whether this Mautic's email has the method. Mautic 5.2 has no email version or continued sending.
     *
     * @param string $method Method name
     *
     * @return bool
     */
    public static function emailHas(string $method): bool
    {
        return (new \ReflectionClass(Email::class))->hasMethod($method);
    }

    /**
     * Check that the plugin can import: an import user and, where Mautic has projects, a valid project name.
     *
     * @return void
     *
     * @throws ConnectorConfigurationException When something is missing
     */
    public function checkConfiguration(): void
    {
        $this->importUser();
        $this->project->check();
    }

    /**
     * Create or update the Mautic email for one export.
     *
     * The email is always saved unpublished. Problems never stop the import: they are flagged at the
     * start of the email's name and listed in its description.
     *
     * @param DesignExport $export    Export to import
     * @param float        $startedAt Unix time with microseconds when the request arrived
     *
     * @return ImportResult
     *
     * @throws ConnectorConfigurationException When the import user or the project name is missing
     * @throws ImportBusyException             When an identical export of the design is still being imported
     */
    public function import(DesignExport $export, float $startedAt): ImportResult
    {
        $user      = $this->importUser();
        $projectId = $this->project->prepare($user);
        $arrival   = $this->arrival($startedAt);

        $claim = $this->claim($export, $startedAt, $arrival);
        if (!$claim->isClaimed()) {
            return new ImportResult(Claim::SUPERSEDED === $claim->state ? ImportResult::SUPERSEDED : ImportResult::UNCHANGED, $claim->emailId);
        }

        $connection = $this->entityManager->getConnection();
        $deadline   = max($startedAt + $this->settings->imageSeconds(), microtime(true) + 10.0);

        try {
            $images   = $this->images->import($this->unsubscribe->normalise($export->html), $deadline);
            $problems = [
                ConnectorSettings::MARKER_UNSUBSCRIBE => $this->unsubscribe->check($images->html),
                ConnectorSettings::MARKER_IMAGES      => array_map(
                    static fn (array $failure): string => $failure['url'].': '.$failure['reason'],
                    $images->failures
                ),
                ConnectorSettings::MARKER_OTHER       => [],
            ];
            $wanted = $this->wantedType($export->tags, $problems);

            $connection->beginTransaction();
            $lock   = $this->store->lockForCompletion($export->designId, $export->hash, $arrival);
            $result = new ImportResult(ImportResult::SUPERSEDED, $lock['emailId']);
            if ($lock['owned']) {
                $result = $this->save($export, $images->html, $problems, $wanted, $lock['emailId'], $projectId, $user);
                $this->store->complete($export->designId, $export->hash, $arrival, (int) $result->emailId, $this->now(), [] === $problems[ConnectorSettings::MARKER_IMAGES]);
            }
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->abandon($export, $arrival);
            throw $exception;
        }
    }

    /**
     * Write the export into the linked email while it may still change, or into a new email.
     *
     * @param DesignExport                $export    Export to import
     * @param string                      $html      HTML with the copied images
     * @param array<string, list<string>> $problems  Problems by marker kind
     * @param string                      $wanted    Type the tags ask for
     * @param int|null                    $linkedId  Email linked to the design
     * @param int                         $projectId Import project, 0 when this Mautic has no projects
     * @param User                        $user      Import user
     *
     * @return ImportResult
     */
    private function save(DesignExport $export, string $html, array $problems, string $wanted, ?int $linkedId, int $projectId, User $user): ImportResult
    {
        $email = $this->changeableEmail($linkedId);
        $state = null === $email ? ImportResult::CREATED : ImportResult::UPDATED;
        if (null === $email) {
            $email = $this->newEmail($wanted);
        } else {
            $this->applyType($email, $wanted, $problems);
            if (self::emailHas('markForVersionIncrement')) {
                $email->markForVersionIncrement();
            }
            $email->setDateModified(new \DateTime());
        }

        $this->project->place($email, $projectId);
        $email->setTemplate('mautic_code_mode');
        $email->setCustomHtml($html);
        $email->setPreheaderText(null);
        $email->setIsPublished(false);
        $this->checkLinks($html, $problems);

        if ('' === $export->subject) {
            $problems[ConnectorSettings::MARKER_OTHER][] = 'The design has no subject.';
        }
        $email->setSubject('' === $export->subject ? null : mb_substr($export->subject, 0, self::SUBJECT_LIMIT));
        $email->setName($this->name($export, $problems));
        $email->setDescription($this->description($export, $problems));

        $this->actAs->run($user, fn () => $this->emailModel->saveEntity($email, false));

        return new ImportResult($state, $email->getId(), $this->count($problems));
    }

    /**
     * Undo a failed import: roll back its transaction and give up its claim on the design.
     *
     * @param DesignExport $export  Export that failed
     * @param int          $arrival When the request arrived, the claim's own mark
     *
     * @return void
     */
    private function abandon(DesignExport $export, int $arrival): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        try {
            $this->store->release($export->designId, $export->hash, $arrival);
        } catch (\Throwable) {
        }
    }

    /**
     * Claim the design, waiting while an identical export of it is still being imported.
     *
     * The wait ends when that import finishes (unchanged), gives up its claim or goes stale (claimed by
     * this export), a newer export of the design arrives (superseded), or the wait time runs out.
     *
     * @param DesignExport $export    Export to import
     * @param float        $startedAt Unix time with microseconds when the request arrived
     * @param int          $arrival   The same time in whole microseconds, the claim's own mark
     *
     * @return Claim
     *
     * @throws ImportBusyException When the identical import is still running at the end of the wait
     */
    private function claim(DesignExport $export, float $startedAt, int $arrival): Claim
    {
        $claim = $this->store->claim($export->designId, $export->hash, $arrival, $this->now(), $this->settings->claimSeconds());
        while (Claim::IN_PROGRESS === $claim->state && microtime(true) < $startedAt + $this->settings->waitSeconds()) {
            usleep(500000);
            $claim = $this->store->claim($export->designId, $export->hash, $arrival, $this->now(), $this->settings->claimSeconds());
        }

        if (Claim::IN_PROGRESS === $claim->state) {
            throw new ImportBusyException('An identical export of this design is still being imported.');
        }

        return $claim;
    }

    /**
     * Email type the design's tags ask for.
     *
     * The campaign tag makes a campaign email, anything else a segment email. Both tags give a
     * segment email and a problem.
     *
     * @param list<string>                $tags     Design tags
     * @param array<string, list<string>> $problems Problems by marker kind, added to
     *
     * @return string
     */
    private function wantedType(array $tags, array &$problems): string
    {
        $tags     = array_map(fn (string $tag): string => $this->settings->normaliseTag($tag), $tags);
        $campaign = in_array($this->settings->campaignTag(), $tags, true);
        $segment  = in_array($this->settings->segmentTag(), $tags, true);

        if ($campaign && $segment) {
            $problems[ConnectorSettings::MARKER_OTHER][] = 'The design has both type tags ('.$this->settings->campaignTag().' and '
                .$this->settings->segmentTag().'), so it was saved as a segment email.';

            return self::SEGMENT_EMAIL;
        }

        return $campaign ? self::CAMPAIGN_EMAIL : self::SEGMENT_EMAIL;
    }

    /**
     * Linked email when an export may still change it, otherwise null for a new email.
     *
     * The email's row is locked first, so a publish or a delete at the same moment waits for this import
     * or is seen by it. Run it inside the import transaction.
     *
     * @param int|null $emailId Email linked to the design
     *
     * @return Email|null
     */
    private function changeableEmail(?int $emailId): ?Email
    {
        if (null === $emailId) {
            return null;
        }

        $table  = $this->entityManager->getClassMetadata(Email::class)->getTableName();
        $locked = $this->entityManager->getConnection()->fetchOne('SELECT id FROM '.$table.' WHERE id = ? FOR UPDATE', [$emailId]);
        if (false === $locked) {
            return null;
        }

        $email = $this->entityManager->find(Email::class, $emailId);
        if (!$email instanceof Email) {
            return null;
        }

        $this->entityManager->refresh($email);

        return $this->usage->isChangeable($email) ? $email : null;
    }

    /**
     * New email of the given type.
     *
     * @param string $type Email type
     *
     * @return Email
     */
    private function newEmail(string $type): Email
    {
        $email = new Email();
        $email->setEmailType($type);

        return $email;
    }

    /**
     * Change an existing email's type when the tags ask for another one and it is safe.
     *
     * @param Email                       $email    Email being updated
     * @param string                      $wanted   Type the tags ask for
     * @param array<string, list<string>> $problems Problems by marker kind, added to
     *
     * @return void
     */
    private function applyType(Email $email, string $wanted, array &$problems): void
    {
        $current = $email->getEmailType() ?: self::CAMPAIGN_EMAIL;
        if ($current === $wanted) {
            return;
        }

        if (self::SEGMENT_EMAIL === $wanted && $this->usage->isUsedByActions((int) $email->getId())) {
            $problems[ConnectorSettings::MARKER_OTHER][] = 'It stays a campaign email, because a campaign, form or point action sends it.';

            return;
        }

        foreach ($email->getExcludedLists() as $excluded) {
            $email->removeExcludedList($excluded);
        }
        $email->setPublishUp(null);
        $email->setPublishDown(null);
        if (self::emailHas('setContinueSending')) {
            $email->setContinueSending(false);
        }
        $email->setEmailType($wanted);
    }

    /**
     * Add every link that is not a valid address to the problems.
     *
     * @param string                      $html     Email HTML as it will be stored
     * @param array<string, list<string>> $problems Problems by marker kind, added to
     *
     * @return void
     */
    private function checkLinks(string $html, array &$problems): void
    {
        foreach ($this->links->invalidLinks($html) as $url) {
            $problems[ConnectorSettings::MARKER_OTHER][] = 'A link address is not valid: '.$url;
        }
    }

    /**
     * Email name: the markers of every problem kind found, then the design name.
     *
     * @param DesignExport                $export   Export
     * @param array<string, list<string>> $problems Problems by marker kind
     *
     * @return string
     */
    private function name(DesignExport $export, array $problems): string
    {
        $markers = [];
        foreach ($problems as $kind => $list) {
            if ([] !== $list) {
                $markers[] = $this->settings->marker($kind);
            }
        }

        $base = '' !== $export->name ? $export->name : 'RGE Studio design '.$export->designId;

        return mb_substr(trim(implode(' ', array_filter($markers)).' '.$base), 0, self::NAME_LIMIT);
    }

    /**
     * Email description: where it came from, its preheader and every problem found.
     *
     * @param DesignExport                $export   Export
     * @param array<string, list<string>> $problems Problems by marker kind
     *
     * @return string
     */
    private function description(DesignExport $export, array $problems): string
    {
        $lines = ['Imported from RGE Studio. Design '.$export->designId.('' !== $export->status ? ', status '.$export->status : '').'.'];
        if ('' !== $export->preheader) {
            $lines[] = 'Preheader: '.$export->preheader;
        }

        foreach ($problems as $kind => $list) {
            foreach ($list as $problem) {
                $lines[] = $this->settings->marker($kind).' '.$problem;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Total number of problems.
     *
     * @param array<string, list<string>> $problems Problems by marker kind
     *
     * @return int
     */
    private function count(array $problems): int
    {
        return array_sum(array_map('count', $problems));
    }

    /**
     * Mautic user every import is saved as.
     *
     * @return User
     *
     * @throws ConnectorConfigurationException When no user is set or it does not exist
     */
    private function importUser(): User
    {
        $id = $this->settings->userId();
        if ($id <= 0) {
            throw new ConnectorConfigurationException('No import user is set (rge_studio_connector_user_id).');
        }

        $user = $this->userModel->getEntity($id);
        if (!$user instanceof User || null === $user->getId()) {
            throw new ConnectorConfigurationException('The import user does not exist (rge_studio_connector_user_id).');
        }

        return $user;
    }

    /**
     * Request arrival in whole microseconds since 1970, which orders the exports of a design.
     *
     * @param float $startedAt Unix time with microseconds when the request arrived
     *
     * @return int
     */
    private function arrival(float $startedAt): int
    {
        return (int) round($startedAt * 1000000);
    }

    /**
     * Current time in UTC.
     *
     * @return \DateTimeImmutable
     */
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
