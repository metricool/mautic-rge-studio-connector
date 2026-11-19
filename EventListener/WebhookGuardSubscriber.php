<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\EventListener;

use MauticPlugin\RgeStudioConnectorBundle\Controller\WebhookController;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class WebhookGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Build the guard.
     *
     * @param ConnectorSettings $settings Plugin settings
     * @param LoggerInterface   $logger   Mautic logger
     */
    public function __construct(
        private ConnectorSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Subscribe the guard to kernel requests, after routing and before any other request listener reads the body.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    /**
     * Reject webhook calls that are too large or do not carry the shared secret.
     *
     * With no secret configured every call is rejected.
     *
     * @param RequestEvent $event Request event
     *
     * @return void
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || WebhookController::ROUTE !== $request->attributes->get('_route')) {
            return;
        }

        $length = $request->headers->get('Content-Length');
        if (null !== $length && (int) $length > $this->settings->maxBodyBytes()) {
            $event->setResponse(new JsonResponse(['error' => 'The export is larger than the limit.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));

            return;
        }

        $secrets = $this->settings->secrets();
        if ([] === $secrets) {
            $this->logger->error('RGE Studio connector: call rejected, no shared secret is configured.');
            $event->setResponse($this->unauthorised());

            return;
        }

        $given = (string) $request->headers->get($this->settings->secretHeader(), '');
        foreach ($secrets as $secret) {
            if ('' !== $given && hash_equals($secret, $given)) {
                if (strlen($request->getContent()) > $this->settings->maxBodyBytes()) {
                    $event->setResponse(new JsonResponse(['error' => 'The export is larger than the limit.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
                }

                return;
            }
        }

        $this->logger->warning('RGE Studio connector: call rejected, wrong or missing shared secret.', ['ip' => $request->getClientIp()]);
        $event->setResponse($this->unauthorised());
    }

    /**
     * Answer for a call without the right secret.
     *
     * @return JsonResponse
     */
    private function unauthorised(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorised.'], Response::HTTP_UNAUTHORIZED);
    }
}
