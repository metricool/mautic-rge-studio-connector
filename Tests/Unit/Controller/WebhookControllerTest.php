<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\Controller;

use MauticPlugin\RgeStudioConnectorBundle\Controller\WebhookController;
use MauticPlugin\RgeStudioConnectorBundle\Import\ConnectorConfigurationException;
use MauticPlugin\RgeStudioConnectorBundle\Import\EmailImporter;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportBusyException;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportResult;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExport;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExportParser;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SpyLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class WebhookControllerTest extends TestCase
{
    private SpyLogger $logger;

    /**
     * @var EmailImporter&MockObject
     */
    private EmailImporter $importer;

    /**
     * Start every test with an empty log and an importer that expects nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->logger   = new SpyLogger();
        $this->importer = $this->createMock(EmailImporter::class);
    }

    /**
     * The connection test checks the configuration and answers ok.
     *
     * @return void
     */
    public function testConnectionTestChecksTheConfiguration(): void
    {
        $this->importer->expects(self::once())->method('checkConfiguration');
        $this->importer->expects(self::never())->method('import');

        $response = $this->receive(' ');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
        self::assertSame([['level' => 'info', 'message' => 'RGE Studio connector: connection test answered.', 'context' => []]], $this->logger->records);
    }

    /**
     * A body over the limit is rejected, one at the limit is read.
     *
     * @return void
     */
    public function testBodySizeLimit(): void
    {
        $atLimit = $this->receive(str_repeat(' ', 1024), ['rge_studio_connector_max_body_bytes' => 1024]);
        $over    = $this->receive(str_repeat(' ', 1025), ['rge_studio_connector_max_body_bytes' => 1024]);

        self::assertSame(200, $atLimit->getStatusCode());
        self::assertSame(413, $over->getStatusCode());
        self::assertSame('{"error":"The export is larger than the limit."}', $over->getContent());
    }

    /**
     * An unreadable body is rejected with its reason and logged without its content.
     *
     * @return void
     */
    public function testUnreadableBodyIsRejectedWithItsReason(): void
    {
        $response = $this->receive('{"html":');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"error":"The body is not valid JSON."}', $response->getContent());
        self::assertSame([[
            'level'   => 'warning',
            'message' => 'RGE Studio connector: unreadable export rejected.',
            'context' => ['reason' => 'The body is not valid JSON.', 'bytes' => 8],
        ]], $this->logger->records);
    }

    /**
     * An export imported is answered with its result and logged with counts only.
     *
     * @return void
     */
    public function testImportedExportIsAnsweredAndLogged(): void
    {
        $arrival = (string) (microtime(true) - 5);
        $this->importer->expects(self::once())->method('import')
            ->with(self::callback(static fn (DesignExport $export): bool => '1234567' === $export->designId), (float) $arrival)
            ->willReturn(new ImportResult(ImportResult::CREATED, 42, 2));

        $body     = $this->body();
        $response = $this->receive($body, [], $arrival);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"result":"created","emailId":42,"problems":2}', $response->getContent());
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame('info', $record['level']);
        self::assertSame('RGE Studio connector: export imported.', $record['message']);
        self::assertSame(['designId', 'bytes', 'result', 'emailId', 'problems', 'seconds'], array_keys($record['context']));
        self::assertSame(['1234567', strlen($body), 'created', 42, 2], array_slice(array_values($record['context']), 0, 5));
        self::assertIsFloat($record['context']['seconds']);
        self::assertGreaterThanOrEqual(5.0, $record['context']['seconds']);
        self::assertLessThan(6.0, $record['context']['seconds']);
    }

    /**
     * Without the arrival time the import starts counting now.
     *
     * @return void
     */
    public function testArrivalTimeFallsBackToNow(): void
    {
        $before = microtime(true);
        $this->importer->expects(self::once())->method('import')
            ->with(self::anything(), self::callback(static fn (float $startedAt): bool => $startedAt >= $before && $startedAt <= microtime(true)))
            ->willReturn(new ImportResult(ImportResult::UNCHANGED, 42));

        $response = $this->receive($this->body());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"result":"unchanged","emailId":42,"problems":0}', $response->getContent());
    }

    /**
     * An export that is not an email is ignored and logged.
     *
     * @return void
     */
    public function testExportThatIsNotAnEmailIsIgnored(): void
    {
        $this->importer->expects(self::never())->method('import');

        $response = $this->receive($this->body(['template_type' => 'page']));

        self::assertSame('{"result":"ignored","reason":"not an email"}', $response->getContent());
        self::assertSame([[
            'level'   => 'info',
            'message' => 'RGE Studio connector: export ignored, not an email.',
            'context' => ['designId' => '1234567', 'type' => 'page'],
        ]], $this->logger->records);
    }

    /**
     * Statuses are filtered only when accepted statuses are set.
     *
     * @return void
     */
    public function testStatusesAreFilteredOnlyWhenSet(): void
    {
        $this->importer->expects(self::exactly(2))->method('import')->willReturn(new ImportResult(ImportResult::CREATED, 1));

        $ignored = $this->receive($this->body(['status' => 'draft']), ['rge_studio_connector_accepted_statuses' => ['approved']]);
        $this->receive($this->body(['status' => 'approved']), ['rge_studio_connector_accepted_statuses' => ['approved']]);
        $this->receive($this->body(['status' => 'draft']), ['rge_studio_connector_accepted_statuses' => []]);

        self::assertSame('{"result":"ignored","reason":"status not accepted"}', $ignored->getContent());
        self::assertSame([
            'level'   => 'info',
            'message' => 'RGE Studio connector: export ignored, status not accepted.',
            'context' => ['designId' => '1234567', 'status' => 'draft'],
        ], $this->logger->records[0]);
    }

    /**
     * An identical export still running is answered as busy.
     *
     * @return void
     */
    public function testBusyImportAnswers503(): void
    {
        $this->importer->method('import')->willThrowException(new ImportBusyException('An identical export of this design is still being imported.'));

        $body     = $this->body();
        $response = $this->receive($body);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"error":"An identical export of this design is still being imported."}', $response->getContent());
        self::assertSame([[
            'level'   => 'info',
            'message' => 'RGE Studio connector: export not applied, an identical one is still being imported.',
            'context' => ['bytes' => strlen($body)],
        ]], $this->logger->records);
    }

    /**
     * A missing setting is answered as not configured and logged with the reason.
     *
     * @return void
     */
    public function testMissingConfigurationAnswers503(): void
    {
        $this->importer->method('checkConfiguration')->willThrowException(new ConnectorConfigurationException('No import user is set.'));

        $response = $this->receive('');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"error":"The connector is not configured."}', $response->getContent());
        self::assertSame([['level' => 'error', 'message' => 'RGE Studio connector: not configured. No import user is set.', 'context' => []]], $this->logger->records);
    }

    /**
     * Any other failure is answered 500 and logged with its class and message.
     *
     * @return void
     */
    public function testUnexpectedFailureAnswers500(): void
    {
        $this->importer->method('import')->willThrowException(new \LogicException('broken'));

        $body     = $this->body();
        $response = $this->receive($body);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"error":"The import failed."}', $response->getContent());
        self::assertSame([[
            'level'   => 'error',
            'message' => 'RGE Studio connector: import failed.',
            'context' => ['exception' => \LogicException::class, 'message' => 'broken', 'bytes' => strlen($body)],
        ]], $this->logger->records);
    }

    /**
     * Send a body to the controller.
     *
     * @param string               $body       Request body
     * @param array<string, mixed> $parameters Settings to change
     * @param string|null          $arrival    Arrival time as the web server gives it
     *
     * @return JsonResponse
     */
    private function receive(string $body, array $parameters = [], ?string $arrival = null): JsonResponse
    {
        $server     = null === $arrival ? [] : ['REQUEST_TIME_FLOAT' => $arrival];
        $controller = new WebhookController($this->importer, new DesignExportParser(), SettingsStub::create($parameters), $this->logger);

        return $controller->receiveAction(new Request([], [], [], [], [], $server, $body));
    }

    /**
     * Export body shaped like the Custom Connector's.
     *
     * @param array<string, mixed> $changes Keys to change
     *
     * @return string
     */
    private function body(array $changes = []): string
    {
        return (string) json_encode(array_merge([
            'name'          => 'Spring offer',
            'html'          => '<p><a href="{unsubscribe_url}">Unsubscribe</a></p>',
            'template_type' => 'email',
            'status'        => 'in_review',
            'edit_url'      => 'https://reallygoodemails.com/app/1/2/3/1234567/edit/message',
        ], $changes));
    }
}
