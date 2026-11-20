<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional\Import;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use Mautic\UserBundle\Security\DummyToken;
use MauticPlugin\RgeStudioConnectorBundle\Import\ActAsUser;
use MauticPlugin\RgeStudioConnectorBundle\Import\ConnectorConfigurationException;
use MauticPlugin\RgeStudioConnectorBundle\Import\DesignLinkStore;
use MauticPlugin\RgeStudioConnectorBundle\Import\EmailImporter;
use MauticPlugin\RgeStudioConnectorBundle\Import\EmailUsage;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImageImporter;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportBusyException;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportResult;
use MauticPlugin\RgeStudioConnectorBundle\Import\LinkChecker;
use MauticPlugin\RgeStudioConnectorBundle\Import\MauticImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\NoImportProject;
use MauticPlugin\RgeStudioConnectorBundle\Import\UnsubscribeLinkChecker;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExport;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExportParser;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class EmailImporterTest extends MauticMysqlTestCase
{
    private const PNG     = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
    private const WORKING = '<p><a href="{unsubscribe_url}">Unsubscribe</a></p>';

    /**
     * Every problem is flagged in the name and listed in the description under its marker.
     *
     * @return void
     */
    public function testNameAndDescriptionListEveryProblem(): void
    {
        $result = $this->importer()->import($this->export([
            'html'    => '<p>No link</p><img src="https://cdn.example.org/missing.png">',
            'subject' => '',
            'title'   => '',
            'tags'    => ['mautic-campaign', 'mautic-segment'],
        ]), microtime(true));

        $email = $this->email($result);
        self::assertSame(ImportResult::CREATED, $result->state);
        self::assertSame('list', $email->getEmailType());
        self::assertSame(4, $result->problems);
        self::assertSame('[FIX UNSUBSCRIBE] [FIX IMAGES] [FIX EMAIL] Spring offer', $email->getName());
        self::assertNull($email->getSubject());
        self::assertSame(implode("\n", [
            'Imported from RGE Studio. Design 1234567, status in_review.',
            'Preheader: Twenty percent off',
            '[FIX UNSUBSCRIBE] There is no working unsubscribe link. Use {unsubscribe_url} as the whole link address, or {unsubscribe_text} as text.',
            '[FIX IMAGES] https://cdn.example.org/missing.png: The image server answered 404.',
            '[FIX EMAIL] The design has both type tags (mautic-campaign and mautic-segment), so it was saved as a segment email.',
            '[FIX EMAIL] The design has no subject.',
        ]), $email->getDescription());
    }

    /**
     * An export saved with an image not copied is applied again when sent again, so its images are tried again,
     * while an export with every image copied changes nothing when sent again.
     *
     * @return void
     */
    public function testExportWithImagesNotCopiedIsAppliedAgainWhenSentAgain(): void
    {
        $missing = $this->export(['html' => self::WORKING.'<img src="https://cdn.example.org/missing.png">']);
        $first   = $this->importer()->import($missing, microtime(true));
        $again   = $this->importer()->import($missing, microtime(true));

        self::assertSame(ImportResult::CREATED, $first->state);
        self::assertSame(ImportResult::UPDATED, $again->state);
        self::assertSame($first->emailId, $again->emailId);

        $copied = $this->export(['html' => self::WORKING.'<img src="https://cdn.example.org/a.png">']);
        $this->importer()->import($copied, microtime(true));
        $same = $this->importer()->import($copied, microtime(true));

        self::assertSame(ImportResult::UNCHANGED, $same->state);
        self::assertSame($first->emailId, $same->emailId);
    }

    /**
     * Without a status or a preheader the description holds only where the email comes from.
     *
     * @return void
     */
    public function testDescriptionWithoutStatusAndPreheader(): void
    {
        $result = $this->importer()->import($this->export(['status' => '', 'preheader' => '', 'meta_description' => '']), microtime(true));

        $email = $this->email($result);
        self::assertSame(0, $result->problems);
        self::assertSame('Spring offer', $email->getName());
        self::assertSame('Imported from RGE Studio. Design 1234567.', $email->getDescription());
    }

    /**
     * A design without a name is named after its id.
     *
     * @return void
     */
    public function testNameFallsBackToTheDesignId(): void
    {
        self::assertSame('RGE Studio design 1234567', $this->email($this->importer()->import($this->export(['name' => '']), microtime(true)))->getName());
    }

    /**
     * Long names and subjects are cut by characters, never in the middle of a letter.
     *
     * @return void
     */
    public function testLongTextIsCutByCharacters(): void
    {
        $email = $this->email($this->importer()->import($this->export(['name' => str_repeat('é', 300), 'subject' => str_repeat('é', 300)]), microtime(true)));

        self::assertSame(str_repeat('é', 190), $email->getName());
        self::assertSame(str_repeat('é', 190), $email->getSubject());
    }

    /**
     * Tags are read without regard to case or spaces, and one type tag alone is not a problem.
     *
     * @return void
     */
    public function testTypeTags(): void
    {
        $campaign = $this->importer()->import($this->export(['tags' => [' Mautic-Campaign ']]), microtime(true));
        $segment  = $this->importer()->import($this->export(['tags' => ['mautic-segment'], 'edit_url' => 'https://example.com/app/7654321']), microtime(true));

        self::assertSame('template', $this->email($campaign)->getEmailType());
        self::assertSame('list', $this->email($segment)->getEmailType());
        self::assertSame(0, $segment->problems);
    }

    /**
     * An update resets what the import owns and clears the send settings of the old type.
     *
     * @return void
     */
    public function testUpdateResetsWhatTheImportOwns(): void
    {
        $first = $this->importer()->import($this->export(['tags' => ['mautic-campaign']]), microtime(true));
        $email = $this->email($first);

        $segment = new LeadList();
        $segment->setName('Excluded');
        $segment->setAlias('rge-excluded');
        $segment->setPublicName('Excluded');
        $this->em->persist($segment);
        $email->addExcludedList($segment);
        $email->setPreheaderText('Typed in Mautic');
        $email->setPublishUp(new \DateTime('+1 day'));
        $email->setPublishDown(new \DateTime('+2 days'));
        $email->setDateModified(new \DateTime('2020-01-01 00:00:00'));
        $admin = $this->em->find(User::class, 1);
        self::assertInstanceOf(User::class, $admin);
        $email->setCheckedOut(new \DateTime());
        $email->setCheckedOutBy($admin);
        $this->em->persist($email);
        $this->em->flush();
        self::assertNotNull($this->email($first)->getPublishDown());
        $this->em->clear();

        $second = $this->importer()->import($this->export(['subject' => 'New subject']), microtime(true));

        $updated = $this->email($second);
        self::assertSame(ImportResult::UPDATED, $second->state);
        self::assertSame($first->emailId, $second->emailId);
        self::assertSame('list', $updated->getEmailType());
        self::assertSame('New subject', $updated->getSubject());
        self::assertNull($updated->getPreheaderText());
        self::assertNull($updated->getPublishUp());
        self::assertNull($updated->getPublishDown());
        self::assertCount(0, $updated->getExcludedLists());
        self::assertGreaterThan(new \DateTime('2021-01-01'), $updated->getDateModified());
        self::assertNotNull($updated->getCheckedOut());
    }

    /**
     * An update that keeps the email's type leaves its send settings alone.
     *
     * @return void
     */
    public function testUpdateOfTheSameTypeKeepsTheSendSettings(): void
    {
        $first   = $this->importer()->import($this->export(), microtime(true));
        $email   = $this->email($first);
        $segment = new LeadList();
        $segment->setName('Kept');
        $segment->setAlias('rge-kept');
        $segment->setPublicName('Kept');
        $this->em->persist($segment);
        $email->addExcludedList($segment);
        $this->em->persist($email);
        $this->em->flush();
        $this->em->clear();

        $second = $this->importer()->import($this->export(['subject' => 'New subject']), microtime(true));

        $updated = $this->email($second);
        self::assertSame(ImportResult::UPDATED, $second->state);
        self::assertSame('list', $updated->getEmailType());
        self::assertSame('New subject', $updated->getSubject());
        self::assertCount(1, $updated->getExcludedLists());
    }

    /**
     * An email published behind the importer's back is read fresh from the database, so the export makes a
     * new email instead of changing the published one.
     *
     * @return void
     */
    public function testEmailPublishedMeanwhileIsReadFresh(): void
    {
        $importer = $this->importer();
        $first    = $importer->import($this->export(), microtime(true));
        $cached   = $this->em->find(Email::class, $first->emailId);
        self::assertInstanceOf(Email::class, $cached);
        self::assertFalse($cached->isPublished());
        $table = $this->em->getClassMetadata(Email::class)->getTableName();
        $this->connection->executeStatement('UPDATE '.$table.' SET is_published = 1 WHERE id = ?', [$first->emailId]);

        $second = $importer->import($this->export(['subject' => 'New subject']), microtime(true));

        self::assertSame(ImportResult::CREATED, $second->state);
        self::assertNotSame($first->emailId, $second->emailId);
    }

    /**
     * Where Mautic's email has a version and continued sending (after 5.2), an update raises the version by one
     * and a change of type stops continued sending.
     *
     * @return void
     */
    public function testUpdateRaisesTheVersionAndStopsContinuedSending(): void
    {
        if (!EmailImporter::emailHas('markForVersionIncrement') || !EmailImporter::emailHas('setContinueSending')) {
            self::markTestSkipped('This Mautic has no email version or continued sending.');
        }

        $first = $this->importer()->import($this->export(['tags' => ['mautic-campaign']]), microtime(true));
        $email = $this->email($first);
        $email->setContinueSending(true);
        $this->em->persist($email);
        $this->em->flush();
        $before = $this->email($first);
        self::assertTrue($before->getContinueSending());
        $version = $before->getVersion();
        $this->em->clear();

        $second = $this->importer()->import($this->export(['subject' => 'New subject']), microtime(true));

        $updated = $this->email($second);
        self::assertFalse($updated->getContinueSending());
        self::assertSame($version + 1, $updated->getVersion());
    }

    /**
     * The email features are found exactly where Mautic has them: on 7.2 and later, never on 5.2.
     *
     * @return void
     */
    public function testEmailFeaturesFollowTheMauticVersion(): void
    {
        $newer = class_exists(ProjectModel::class);

        self::assertSame($newer, EmailImporter::emailHas('markForVersionIncrement'));
        self::assertSame($newer, EmailImporter::emailHas('setContinueSending'));
        self::assertTrue(EmailImporter::emailHas('setPublishDown'));
        self::assertFalse(EmailImporter::emailHas('noSuchMethod'));
    }

    /**
     * A campaign email that an action sends stays a campaign email, and says why.
     *
     * @return void
     */
    public function testCampaignEmailSentByAnActionStaysACampaignEmail(): void
    {
        $first = $this->importer()->import($this->export(['tags' => ['mautic-campaign']]), microtime(true));
        $this->campaignStep((int) $first->emailId);

        $second = $this->importer()->import($this->export(['subject' => 'New subject']), microtime(true));

        $email = $this->email($second);
        self::assertSame('template', $email->getEmailType());
        self::assertStringEndsWith('[FIX EMAIL] It stays a campaign email, because a campaign, form or point action sends it.', (string) $email->getDescription());
    }

    /**
     * A segment email that an action already sends may still become a campaign email.
     *
     * @return void
     */
    public function testSegmentEmailSentByAnActionCanBecomeACampaignEmail(): void
    {
        $first = $this->importer()->import($this->export(), microtime(true));
        $form  = new Form();
        $form->setName('RGE Studio importer test');
        $form->setAlias('rgeimport');
        $this->em->persist($form);
        $action = new Action();
        $action->setForm($form);
        $action->setName('Send email');
        $action->setType('email.send.lead');
        $action->setProperties(['email' => $first->emailId]);
        $this->em->persist($action);
        $this->em->flush();

        $second = $this->importer()->import($this->export(['tags' => ['mautic-campaign']]), microtime(true));

        self::assertSame('template', $this->email($second)->getEmailType());
        self::assertSame(0, $second->problems);
    }

    /**
     * A link that is not a valid address is flagged.
     *
     * @return void
     */
    public function testInvalidLinkIsFlagged(): void
    {
        $result = $this->importer()->import($this->export(['html' => self::WORKING.'<a href="javascript:alert(1)">x</a>']), microtime(true));

        self::assertSame(1, $result->problems);
        self::assertStringEndsWith("\n[FIX EMAIL] A link address is not valid: javascript:alert(1)", (string) $this->email($result)->getDescription());
    }

    /**
     * A marker set to nothing leaves no gap in the name.
     *
     * @return void
     */
    public function testEmptyMarkerLeavesNoGap(): void
    {
        $result = $this->importer(['rge_studio_connector_marker_other' => ''])->import($this->export(['html' => '<p>No link</p>', 'subject' => '', 'title' => '']), microtime(true));

        self::assertSame(2, $result->problems);
        self::assertSame('[FIX UNSUBSCRIBE] Spring offer', $this->email($result)->getName());
    }

    /**
     * The email goes into the project with the configured name, created by the import user when missing.
     *
     * @return void
     */
    public function testEmailGoesIntoTheNamedProject(): void
    {
        $this->requireProjects();
        $other = new Project();
        $other->setName('Other project');
        $this->em->persist($other);
        $this->em->flush();

        $email = $this->email($this->importer()->import($this->export(), microtime(true)));

        $projects = $this->projects($email);
        self::assertCount(1, $projects);
        self::assertSame('RGE Studio', $projects[0]->getName());
        self::assertSame(1, $projects[0]->getCreatedBy());
    }

    /**
     * An update puts the email back into the import project when it left it, and never adds it twice.
     *
     * @return void
     */
    public function testUpdateAddsTheProjectOnlyWhenMissing(): void
    {
        $this->requireProjects();
        $first = $this->importer()->import($this->export(), microtime(true));
        $email = $this->email($first);
        foreach ($this->projects($email) as $project) {
            $email->removeProject($project);
        }
        $this->em->persist($email);
        $this->em->flush();
        self::assertCount(0, $this->projects($this->email($first)));

        foreach (['Second subject', 'Third subject'] as $subject) {
            $update = $this->importer()->import($this->export(['subject' => $subject]), microtime(true));
            self::assertSame(ImportResult::UPDATED, $update->state);
            self::assertSame($first->emailId, $update->emailId);

            $projects = $this->projects($this->email($first));
            self::assertCount(1, $projects);
            self::assertSame('RGE Studio', $projects[0]->getName());
        }
    }

    /**
     * The connection test checks the project setting, and every save puts the email into the project the
     * import prepared as the import user.
     *
     * @return void
     */
    public function testProjectIsPreparedAndPlacedOnEverySave(): void
    {
        $project = new class() implements ImportProject {
            /**
             * Calls received, in order.
             *
             * @var list<string>
             */
            public array $calls = [];

            /**
             * Record the check.
             *
             * @return void
             */
            public function check(): void
            {
                $this->calls[] = 'check';
            }

            /**
             * Record the user the project is prepared for.
             *
             * @param User $user Import user
             *
             * @return int
             */
            public function prepare(User $user): int
            {
                $this->calls[] = 'prepare '.$user->getId();

                return 42;
            }

            /**
             * Record the project an email is placed into, and whether the email is new.
             *
             * @param Email $email     Email being saved
             * @param int   $projectId Project id from prepare()
             *
             * @return void
             */
            public function place(Email $email, int $projectId): void
            {
                $this->calls[] = 'place '.$projectId.(null === $email->getId() ? ' new' : ' existing');
            }
        };
        $importer = $this->importer([], $project);

        $importer->checkConfiguration();
        $importer->import($this->export(), microtime(true));
        $importer->import($this->export(['subject' => 'New subject']), microtime(true));

        self::assertSame(['check', 'prepare 1', 'place 42 new', 'prepare 1', 'place 42 existing'], $project->calls);
    }

    /**
     * The email is saved as the import user, and the previous security token comes back afterwards.
     *
     * @return void
     */
    public function testSavesAsTheImportUserAndRestoresTheToken(): void
    {
        $storage  = static::getContainer()->get(TokenStorageInterface::class);
        $previous = new DummyToken();
        $storage->setToken($previous);

        $email = $this->email($this->importer()->import($this->export(), microtime(true)));

        self::assertSame($previous, $storage->getToken());
        self::assertSame(1, $email->getCreatedBy());
    }

    /**
     * A missing or wrong setting stops the import and the connection test with a clear reason.
     *
     * @dataProvider wrongSettings
     *
     * @param array<string, mixed> $parameters Settings to change
     * @param string               $message    Reason expected
     *
     * @return void
     */
    #[DataProvider('wrongSettings')]
    public function testWrongSettingsStopTheImport(array $parameters, string $message): void
    {
        $importer = $this->importer($parameters);

        try {
            $importer->checkConfiguration();
            self::fail('The connection test passed.');
        } catch (ConnectorConfigurationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        $this->expectException(ConnectorConfigurationException::class);
        $this->expectExceptionMessage($message);
        $importer->import($this->export(), microtime(true));
    }

    /**
     * Settings that stop the import, with their reason.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function wrongSettings(): iterable
    {
        yield 'no user' => [['rge_studio_connector_user_id' => 0], 'No import user is set (rge_studio_connector_user_id).'];
        yield 'unknown user' => [['rge_studio_connector_user_id' => 999999], 'The import user does not exist (rge_studio_connector_user_id).'];
    }

    /**
     * Where Mautic has projects, an empty or too long project name stops the import and the connection test.
     *
     * @return void
     */
    public function testWrongProjectNameStopsTheImport(): void
    {
        $this->requireProjects();
        foreach ([' ', str_repeat('é', 192)] as $name) {
            $importer = $this->importer(['rge_studio_connector_project' => $name]);
            foreach ([fn () => $importer->checkConfiguration(), fn () => $importer->import($this->export(), microtime(true))] as $call) {
                try {
                    $call();
                    self::fail('The import project name was accepted.');
                } catch (ConnectorConfigurationException $exception) {
                    self::assertSame('The import project name must have 1 to 191 characters (rge_studio_connector_project).', $exception->getMessage());
                }
            }
        }
    }

    /**
     * A project name of exactly 191 characters is accepted.
     *
     * @return void
     */
    public function testProjectNameAtTheLimitIsAccepted(): void
    {
        $this->requireProjects();
        $this->importer(['rge_studio_connector_project' => str_repeat('é', 191)])->checkConfiguration();

        $this->addToAssertionCount(1);
    }

    /**
     * An identical export still running makes the import give up as busy at once when its wait is already over,
     * and wait first otherwise.
     *
     * @return void
     */
    public function testIdenticalExportStillRunningIsBusyAfterTheWait(): void
    {
        $export  = $this->export();
        $earlier = (int) round((microtime(true) - 10) * 1000000);
        static::getContainer()->get(DesignLinkStore::class)->claim($export->designId, $export->hash, $earlier, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 60);
        $importer = $this->importer(['rge_studio_connector_wait_seconds' => 1]);

        $late = microtime(true);
        try {
            $importer->import($export, $late - 5);
            self::fail('The import did not give up.');
        } catch (ImportBusyException) {
        }
        self::assertLessThan(0.5, microtime(true) - $late);

        $start = microtime(true);
        try {
            $importer->import($export, $start);
            self::fail('The import did not wait.');
        } catch (ImportBusyException $exception) {
            self::assertSame('An identical export of this design is still being imported.', $exception->getMessage());
        }
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $start);
    }

    /**
     * An export that arrived before the one already applied is answered as superseded and leaves the email alone.
     *
     * @return void
     */
    public function testOlderExportArrivingLateIsSuperseded(): void
    {
        $now   = microtime(true);
        $newer = $this->importer()->import($this->export(['subject' => 'Newer subject']), $now);

        $older = $this->importer()->import($this->export(['subject' => 'Older subject']), $now - 10);

        self::assertSame(ImportResult::SUPERSEDED, $older->state);
        self::assertSame($newer->emailId, $older->emailId);
        self::assertSame('Newer subject', $this->email($newer)->getSubject());
    }

    /**
     * Skip the test when this Mautic has no projects (before 7.0).
     *
     * @return void
     */
    private function requireProjects(): void
    {
        if (!class_exists(ProjectModel::class)) {
            self::markTestSkipped('This Mautic has no projects.');
        }
    }

    /**
     * Projects the email is in.
     *
     * @param Email $email Email
     *
     * @return list<Project>
     */
    private function projects(Email $email): array
    {
        $projects = [];
        foreach ($email->getProjects() as $project) {
            self::assertInstanceOf(Project::class, $project);
            $projects[] = $project;
        }

        return $projects;
    }

    /**
     * Importer over the test database, with images answered by a mock client.
     *
     * @param array<string, mixed> $parameters Settings to change
     * @param ImportProject|null   $project    Import project handler, by default the one for this Mautic
     *
     * @return EmailImporter
     */
    private function importer(array $parameters = [], ?ImportProject $project = null): EmailImporter
    {
        $container = static::getContainer();
        $settings  = SettingsStub::create(array_merge(['rge_studio_connector_user_id' => 1], $parameters));
        $client    = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'missing')
            ? new MockResponse('', ['http_code' => 404])
            : new MockResponse(self::PNG));
        $actAs   = new ActAsUser($container->get(TokenStorageInterface::class));
        $project ??= class_exists(ProjectModel::class)
            ? new MauticImportProject($settings, $container->get(ProjectModel::class), $this->em, $container->get(ManagerRegistry::class), $actAs)
            : new NoImportProject();

        return new EmailImporter(
            $settings,
            $container->get(DesignLinkStore::class),
            new ImageImporter($client, $container->get(PathsHelper::class), $settings),
            new UnsubscribeLinkChecker($settings, UnsubscribeLinkChecker::mauticHasHeaderRule()),
            $container->get(EmailUsage::class),
            $container->get(EmailModel::class),
            $project,
            $container->get(UserModel::class),
            $actAs,
            new LinkChecker(),
            $this->em,
        );
    }

    /**
     * Export shaped like the Custom Connector's.
     *
     * @param array<string, mixed> $changes Keys to change
     *
     * @return DesignExport
     */
    private function export(array $changes = []): DesignExport
    {
        return (new DesignExportParser())->parse((string) json_encode(array_merge([
            'name'          => 'Spring offer',
            'html'          => self::WORKING,
            'template_type' => 'email',
            'subject'       => 'Our spring offer',
            'preheader'     => 'Twenty percent off',
            'status'        => 'in_review',
            'edit_url'      => 'https://reallygoodemails.com/app/1/2/3/1234567/edit/message',
            'tags'          => [],
        ], $changes)));
    }

    /**
     * Email an import result points to, read fresh from the database.
     *
     * @param ImportResult $result Import result
     *
     * @return Email
     */
    private function email(ImportResult $result): Email
    {
        $this->em->clear();
        $email = $this->em->find(Email::class, $result->emailId);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * Campaign step that sends the email.
     *
     * @param int $emailId Email id
     *
     * @return void
     */
    private function campaignStep(int $emailId): void
    {
        $campaign = new Campaign();
        $campaign->setName('RGE Studio importer test');
        $this->em->persist($campaign);

        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName('Send email');
        $event->setType('email.send');
        $event->setEventType(Event::TYPE_ACTION);
        $event->setTriggerMode('immediate');
        $event->setProperties(['email' => $emailId]);
        $event->setChannel('email');
        $event->setChannelId($emailId);
        $this->em->persist($event);
        $this->em->flush();
    }
}
