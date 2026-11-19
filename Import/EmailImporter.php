<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Import;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Validator\ValidEmailLinks;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use Mautic\UserBundle\Security\DummyToken;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExport;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EmailImporter
{
    public const SEGMENT_EMAIL  = 'list';
    public const CAMPAIGN_EMAIL = 'template';

    private const NAME_LIMIT    = 190;
    private const SUBJECT_LIMIT = 190;
    private const PROJECT_LIMIT = 191;

    /**
     * Build the importer.
     *
     * @param ConnectorSettings      $settings      Plugin settings
     * @param DesignLinkStore        $store         Design to email links
     * @param ImageImporter          $images        Image copier
     * @param UnsubscribeLinkChecker $unsubscribe   Unsubscribe link checks
     * @param EmailUsage             $usage         Checks on how an email is used
     * @param EmailModel             $emailModel    Mautic email model
     * @param ProjectModel           $projectModel  Mautic project model
     * @param UserModel              $userModel     Mautic user model
     * @param TokenStorageInterface  $tokenStorage  Security token storage
     * @param ValidatorInterface     $validator     Symfony validator
     * @param EntityManagerInterface $entityManager Entity manager
     * @param ManagerRegistry        $registry      Doctrine registry, to reset a closed entity manager
     */
    public function __construct(
        private ConnectorSettings $settings,
        private DesignLinkStore $store,
        private ImageImporter $images,
        private UnsubscribeLinkChecker $unsubscribe,
        private EmailUsage $usage,
        private EmailModel $emailModel,
        private ProjectModel $projectModel,
        private UserModel $userModel,
        private TokenStorageInterface $tokenStorage,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
    ) {
    }

    /**
     * Check that the plugin can import: an import user and a valid project name.
     *
     * @return void
     *
     * @throws ConnectorConfigurationException When something is missing
     */
    public function checkConfiguration(): void
    {
        $this->importUser();
        $this->projectName();
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
        $this->importUser();
        $project = $this->project();
        $user    = $this->importUser();

        $claim = $this->claim($export, $startedAt);
        if (!$claim->isClaimed()) {
            return new ImportResult(ImportResult::UNCHANGED, $claim->emailId);
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
            $lock = $this->store->lockForCompletion($export->designId, $export->hash);
            if (!$lock['owned']) {
                $connection->commit();

                return new ImportResult(ImportResult::SUPERSEDED, $lock['emailId']);
            }

            $email = $this->changeableEmail($lock['emailId']);
            $state = null === $email ? ImportResult::CREATED : ImportResult::UPDATED;
            if (null === $email) {
                $email = $this->newEmail($wanted, $project);
            } else {
                $this->applyType($email, $wanted, $problems);
                $email->markForVersionIncrement();
                $email->setDateModified(new \DateTime());
            }

            $email->setTemplate('mautic_code_mode');
            $email->setCustomHtml($images->html);
            $email->setPreheaderText(null);
            $email->setIsPublished(false);
            $this->checkLinks($email, $problems);

            if ('' === $export->subject) {
                $problems[ConnectorSettings::MARKER_OTHER][] = 'The design has no subject.';
            }
            $email->setSubject('' === $export->subject ? null : mb_substr($export->subject, 0, self::SUBJECT_LIMIT));
            $email->setName($this->name($export, $problems));
            $email->setDescription($this->description($export, $problems));

            $this->saveAs($user, fn () => $this->emailModel->saveEntity($email, false));
            $this->store->complete($export->designId, $export->hash, (int) $email->getId(), $this->now());
            $connection->commit();

            return new ImportResult($state, $email->getId(), $this->count($problems));
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            try {
                $this->store->release($export->designId, $export->hash);
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    /**
     * Claim the design, waiting while an identical export of it is still being imported.
     *
     * The wait ends when that import finishes (unchanged), gives up its claim or goes stale (claimed by
     * this export), or the wait time runs out.
     *
     * @param DesignExport $export    Export to import
     * @param float        $startedAt Unix time with microseconds when the request arrived
     *
     * @return Claim
     *
     * @throws ImportBusyException When the identical import is still running at the end of the wait
     */
    private function claim(DesignExport $export, float $startedAt): Claim
    {
        $claim = $this->store->claim($export->designId, $export->hash, $this->now(), $this->settings->claimSeconds());
        while (Claim::IN_PROGRESS === $claim->state && microtime(true) < $startedAt + $this->settings->waitSeconds()) {
            usleep(500000);
            $claim = $this->store->claim($export->designId, $export->hash, $this->now(), $this->settings->claimSeconds());
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
     * New unpublished code-mode email in the import project.
     *
     * @param string  $type    Email type
     * @param Project $project Import project
     *
     * @return Email
     */
    private function newEmail(string $type, Project $project): Email
    {
        $email = new Email();
        $email->setEmailType($type);
        $email->addProject($project);

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
        $email->setContinueSending(false);
        $email->setEmailType($wanted);
    }

    /**
     * Add Mautic's own link check to the problems.
     *
     * @param Email                       $email    Email with its HTML set
     * @param array<string, list<string>> $problems Problems by marker kind, added to
     *
     * @return void
     */
    private function checkLinks(Email $email, array &$problems): void
    {
        foreach ($this->validator->validate($email, new ValidEmailLinks()) as $violation) {
            $problems[ConnectorSettings::MARKER_OTHER][] = 'Mautic rejects a link: '.$violation->getMessage();
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
     * Import project, created when missing.
     *
     * @return Project
     *
     * @throws ConnectorConfigurationException When the project name is invalid or the project cannot be created
     */
    private function project(): Project
    {
        $name    = $this->projectName();
        $project = $this->projectModel->getRepository()->findOneBy(['name' => $name]);
        if ($project instanceof Project) {
            return $project;
        }

        $project = new Project();
        $project->setName($name);

        try {
            $this->saveAs($this->importUser(), fn () => $this->projectModel->saveEntity($project));

            return $project;
        } catch (UniqueConstraintViolationException) {
            $this->registry->resetManager();
            $found = $this->projectModel->getRepository()->findOneBy(['name' => $name]);
            if ($found instanceof Project) {
                return $found;
            }

            throw new ConnectorConfigurationException('The import project could not be created.');
        }
    }

    /**
     * Configured project name, checked.
     *
     * @return string
     *
     * @throws ConnectorConfigurationException When it is empty or too long
     */
    private function projectName(): string
    {
        $name = $this->settings->projectName();
        if ('' === $name || mb_strlen($name) > self::PROJECT_LIMIT) {
            throw new ConnectorConfigurationException('The import project name must have 1 to 191 characters (rge_studio_connector_project).');
        }

        return $name;
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
     * Run a save as the import user, then put the previous security token back.
     *
     * The token is restored before the response, so no session or cookie is created for the caller.
     *
     * @param User     $user Import user
     * @param callable $save Save to run
     *
     * @return void
     */
    private function saveAs(User $user, callable $save): void
    {
        $previous = $this->tokenStorage->getToken();
        $token    = new DummyToken();
        $token->setUser($user);
        $this->tokenStorage->setToken($token);

        try {
            $save();
        } finally {
            $this->tokenStorage->setToken($previous);
        }
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
