<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\EventListener;

use MauticPlugin\RgeStudioConnectorBundle\Controller\WebhookController;
use MauticPlugin\RgeStudioConnectorBundle\Settings\ConnectorSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
            $event->setResponse($this->tooLarge());

            return;
        }

        $secrets = $this->settings->secrets();
        if ([] === $secrets) {
            $this->logger->error('RGE Studio connector: call rejected, no shared secret is configured.');
            $event->setResponse($this->unauthorised());

            return;
        }

        if (!$this->carriesSecret($request, $secrets)) {
            $this->logger->warning('RGE Studio connector: call rejected, wrong or missing shared secret.', ['ip' => $request->getClientIp()]);
            $event->setResponse($this->unauthorised());

            return;
        }

        if (strlen($request->getContent()) > $this->settings->maxBodyBytes()) {
            $event->setResponse($this->tooLarge());
        }
    }

    /**
     * Whether the call carries one of the accepted secrets.
     *
     * @param Request      $request Incoming request
     * @param list<string> $secrets Accepted secrets
     *
     * @return bool
     */
    private function carriesSecret(Request $request, array $secrets): bool
    {
        $given = (string) $request->headers->get($this->settings->secretHeader(), '');
        if ('' === $given) {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals($secret, $given)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answer for a body over the size limit.
     *
     * @return JsonResponse
     */
    private function tooLarge(): JsonResponse
    {
        return new JsonResponse(['error' => 'The export is larger than the limit.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
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
