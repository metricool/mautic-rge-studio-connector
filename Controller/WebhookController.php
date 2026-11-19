<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Controller;

use MauticPlugin\RgeStudioConnectorBundle\Import\ConnectorConfigurationException;
use MauticPlugin\RgeStudioConnectorBundle\Import\EmailImporter;
use MauticPlugin\RgeStudioConnectorBundle\Import\ImportBusyException;
use MauticPlugin\RgeStudioConnectorBundle\Payload\DesignExportParser;
use MauticPlugin\RgeStudioConnectorBundle\Payload\InvalidExportException;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController
{
    public const ROUTE = 'rge_studio_connector_webhook';

    /**
     * Build the controller.
     *
     * @param EmailImporter      $importer Email importer
     * @param DesignExportParser $parser   Export reader
     * @param ConnectorSettings  $settings Plugin settings
     * @param LoggerInterface    $logger   Mautic logger
     */
    public function __construct(
        private EmailImporter $importer,
        private DesignExportParser $parser,
        private ConnectorSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Receive one call from the RGE Studio Custom Connector.
     *
     * The shared secret is already checked by WebhookGuardSubscriber. An empty body is the
     * connector's connection test. Every readable export answers 200, because RGE Studio shows
     * nothing but an endless spinner for any other answer, and problems are flagged on the email.
     *
     * @param Request $request Incoming request
     *
     * @return JsonResponse
     */
    public function receiveAction(Request $request): JsonResponse
    {
        $startedAt = (float) $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
        $body      = $request->getContent();

        if (strlen($body) > $this->settings->maxBodyBytes()) {
            return new JsonResponse(['error' => 'The export is larger than the limit.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            if ('' === trim($body)) {
                $this->importer->checkConfiguration();
                $this->logger->info('RGE Studio connector: connection test answered.');

                return new JsonResponse(['ok' => true]);
            }

            $export = $this->parser->parse($body);

            if (!$export->isEmail()) {
                $this->logger->info('RGE Studio connector: export ignored, not an email.', ['designId' => $export->designId, 'type' => $export->templateType]);

                return new JsonResponse(['result' => 'ignored', 'reason' => 'not an email']);
            }

            $accepted = $this->settings->acceptedStatuses();
            if ([] !== $accepted && !in_array($export->status, $accepted, true)) {
                $this->logger->info('RGE Studio connector: export ignored, status not accepted.', ['designId' => $export->designId, 'status' => $export->status]);

                return new JsonResponse(['result' => 'ignored', 'reason' => 'status not accepted']);
            }

            $result = $this->importer->import($export, $startedAt);
            $this->logger->info('RGE Studio connector: export imported.', [
                'designId' => $export->designId,
                'bytes'    => strlen($body),
                'result'   => $result->state,
                'emailId'  => $result->emailId,
                'problems' => $result->problems,
                'seconds'  => round(microtime(true) - $startedAt, 2),
            ]);

            return new JsonResponse($result->toArray());
        } catch (InvalidExportException $exception) {
            $this->logger->warning('RGE Studio connector: unreadable export rejected.', ['reason' => $exception->getMessage(), 'bytes' => strlen($body)]);

            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ImportBusyException $exception) {
            $this->logger->info('RGE Studio connector: export not applied, an identical one is still being imported.', ['bytes' => strlen($body)]);

            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (ConnectorConfigurationException $exception) {
            $this->logger->error('RGE Studio connector: not configured. '.$exception->getMessage());

            return new JsonResponse(['error' => 'The connector is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $exception) {
            $this->logger->error('RGE Studio connector: import failed.', ['exception' => $exception::class, 'message' => $exception->getMessage(), 'bytes' => strlen($body)]);

            return new JsonResponse(['error' => 'The import failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
