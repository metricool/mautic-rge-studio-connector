<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\RgeStudioConnectorBundle\Entity\DesignLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class WebhookControllerTest extends MauticMysqlTestCase
{
    private const SECRET = 'test-secret-value';
    private const URL    = '/rge-studio/webhook';
    private const PNG    = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    /**
     * Boot Mautic with the connector set to the fixture admin user, a known secret and a small body limit.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->configParams['rge_studio_connector_secret']         = self::SECRET;
        $this->configParams['rge_studio_connector_user_id']        = 1;
        $this->configParams['rge_studio_connector_max_body_bytes'] = 4096;
        parent::setUp();

        $admin = $this->em->getRepository(User::class)->find(1);
        self::assertInstanceOf(User::class, $admin);
        self::assertSame('admin', $admin->getUserIdentifier());

        static::getContainer()->set('rge_studio_connector.http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse(self::PNG)));
    }

    /**
     * The connector's connection test, an empty body, is answered with 200 and creates nothing.
     *
     * @return void
     */
    public function testConnectionTestAnswersOk(): void
    {
        $this->post('', self::SECRET);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Email::class)->count([]));
    }

    /**
     * Broken JSON reaches the plugin and gets its 422, and a body of only whitespace is a connection test.
     *
     * @return void
     */
    public function testUnreadableBodyAnswers422AndWhitespaceIsAConnectionTest(): void
    {
        $this->post('{"html":', self::SECRET);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());

        $this->post("\n", self::SECRET);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * An export identical to the last one applied stops an older export still running from overwriting it.
     *
     * @return void
     */
    public function testIdenticalExportClearsAnOlderRunningClaim(): void
    {
        $this->post($this->body(), self::SECRET);
        $table = $this->em->getClassMetadata(DesignLink::class)->getTableName();
        $this->connection->executeStatement('UPDATE '.$table.' SET pending_hash = ?, claimed_at = UTC_TIMESTAMP()', [str_repeat('a', 64)]);

        $this->post($this->body(), self::SECRET);

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('unchanged', $answer['result']);
        self::assertNull($this->connection->fetchOne('SELECT pending_hash FROM '.$table));
    }

    /**
     * An export creates an unpublished code-mode segment email in the import project, with its image copied.
     *
     * @return void
     */
    public function testExportCreatesAnUnpublishedEmail(): void
    {
        $this->post($this->body(), self::SECRET);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('created', $answer['result']);

        $email = $this->em->getRepository(Email::class)->find($answer['emailId']);
        self::assertInstanceOf(Email::class, $email);
        self::assertFalse($email->isPublished());
        self::assertSame('list', $email->getEmailType());
        self::assertSame('mautic_code_mode', $email->getTemplate());
        self::assertSame('Spring offer', $email->getName());
        self::assertSame('Our spring offer', $email->getSubject());
        self::assertNull($email->getPreheaderText());
        self::assertStringNotContainsString('media.beefree.cloud', (string) $email->getCustomHtml());
        self::assertStringContainsString('/media/images/rge-studio/'.hash('sha256', self::PNG).'.png', (string) $email->getCustomHtml());
        self::assertStringContainsString('Preheader: Twenty percent off', (string) $email->getDescription());
        self::assertSame(['RGE Studio'], array_map(static fn ($project) => $project->getName(), $email->getProjects()->toArray()));
        self::assertNotNull($email->getCreatedBy());
    }

    /**
     * The same export again changes nothing, a changed one updates the same email and may change its type.
     *
     * @return void
     */
    public function testReExportUpdatesTheSameEmail(): void
    {
        $this->post($this->body(), self::SECRET);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->post($this->body(), self::SECRET);
        $same = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('unchanged', $same['result']);
        self::assertSame($first['emailId'], $same['emailId']);

        $this->post($this->body(['tags' => ['mautic-campaign'], 'subject' => 'New subject']), self::SECRET);
        $changed = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('updated', $changed['result']);
        self::assertSame($first['emailId'], $changed['emailId']);

        $this->em->clear();
        $email = $this->em->getRepository(Email::class)->find($first['emailId']);
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('template', $email->getEmailType());
        self::assertSame('New subject', $email->getSubject());
        self::assertSame(1, $this->em->getRepository(DesignLink::class)->count([]));
    }

    /**
     * A published email is never changed, even one scheduled for later: a new export of its design creates a new email.
     *
     * @param bool $scheduled Whether the email is published with a send time in the future
     *
     * @return void
     */
    #[DataProvider('publishedStates')]
    public function testPublishedEmailIsNeverChanged(bool $scheduled): void
    {
        $this->post($this->body(), self::SECRET);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);

        $email = $this->em->getRepository(Email::class)->find($first['emailId']);
        self::assertInstanceOf(Email::class, $email);
        $email->setIsPublished(true);
        if ($scheduled) {
            $email->setPublishUp(new \DateTime('+1 day'));
        }
        $this->em->persist($email);
        $this->em->flush();
        $this->em->clear();

        $this->post($this->body(['subject' => 'Second version']), self::SECRET);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('created', $second['result']);
        self::assertNotSame($first['emailId'], $second['emailId']);
        $published = $this->em->getRepository(Email::class)->find($first['emailId']);
        self::assertInstanceOf(Email::class, $published);
        self::assertSame('Our spring offer', $published->getSubject());
        self::assertTrue($published->isPublished(false));
    }

    /**
     * Published now, and published with a send time tomorrow.
     *
     * @return iterable<string, array{bool}>
     */
    public static function publishedStates(): iterable
    {
        yield 'published' => [false];
        yield 'scheduled' => [true];
    }

    /**
     * A broken unsubscribe link still creates the email, flagged in its name and description.
     *
     * @return void
     */
    public function testBrokenUnsubscribeLinkIsFlagged(): void
    {
        $this->post($this->body(['html' => '<html><body><p>No link here</p></body></html>']), self::SECRET);

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $email = $this->em->getRepository(Email::class)->find($answer['emailId']);
        self::assertInstanceOf(Email::class, $email);
        self::assertStringStartsWith('[FIX UNSUBSCRIBE] ', (string) $email->getName());
        self::assertFalse($email->isPublished());
        self::assertStringContainsString('no working unsubscribe link', (string) $email->getDescription());
    }

    /**
     * Post a body to the webhook.
     *
     * @param string      $body   Request body
     * @param string|null $secret Secret header value, null to leave it out
     *
     * @return void
     */
    private function post(string $body, ?string $secret): void
    {
        $server = ['CONTENT_TYPE' => '' === $body ? '' : 'application/json'];
        if (null !== $secret) {
            $server['HTTP_X_WEBHOOK_SECRET'] = $secret;
        }

        $this->client->request('POST', self::URL, [], [], $server, $body);
    }

    /**
     * Export body shaped like the RGE Studio Custom Connector's.
     *
     * @param array<string, mixed> $changes Keys to change
     *
     * @return string
     */
    private function body(array $changes = []): string
    {
        return (string) json_encode(array_merge([
            'name'           => 'Spring offer',
            'html'           => '<!DOCTYPE html><html lang="en"><head><title>Our spring offer</title></head><body>'
                .'<div class="preheader" style="display:none;">Twenty percent off</div>'
                .'<img src="https://media.beefree.cloud/pub/spring.png" width="600">'
                .'<p><a href="{unsubscribe_url}" target="_blank">{unsubscribe_url}</a></p></body></html>',
            'template_type'  => 'email',
            'structure'      => '{}',
            'subject'        => 'Our spring offer',
            'title'          => 'Our spring offer',
            'preheader'      => 'Twenty percent off',
            'status'         => 'in_review',
            'edit_url'       => 'https://reallygoodemails.com/app/1/2/3/1234567/edit/message',
            'tags'           => [],
            'utm_parameters' => [],
        ], $changes));
    }
}
