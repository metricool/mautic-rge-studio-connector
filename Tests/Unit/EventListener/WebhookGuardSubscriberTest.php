<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\EventListener;

use MauticPlugin\RgeStudioConnectorBundle\Controller\WebhookController;
use MauticPlugin\RgeStudioConnectorBundle\EventListener\WebhookGuardSubscriber;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SettingsStub;
use MauticPlugin\RgeStudioConnectorBundle\Tests\Unit\SpyLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class WebhookGuardSubscriberTest extends TestCase
{
    private SpyLogger $logger;

    /**
     * Start every test with an empty log.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    /**
     * The guard runs on kernel requests after routing.
     *
     * @return void
     */
    public function testSubscribesAfterRouting(): void
    {
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', 20]], WebhookGuardSubscriber::getSubscribedEvents());
    }

    /**
     * Other routes and sub-requests are left alone.
     *
     * @return void
     */
    public function testOtherRoutesAndSubRequestsAreLeftAlone(): void
    {
        $other = $this->event($this->request('', [], 'mautic_dashboard_index'));
        $sub   = $this->event($this->request(''), HttpKernelInterface::SUB_REQUEST);

        $this->guard()->onKernelRequest($other);
        $this->guard()->onKernelRequest($sub);

        self::assertNull($other->getResponse());
        self::assertNull($sub->getResponse());
    }

    /**
     * A call with the current or the previous secret passes untouched.
     *
     * @return void
     */
    public function testCallsWithASecretPass(): void
    {
        $parameters = ['rge_studio_connector_secret' => 'current', 'rge_studio_connector_previous_secret' => 'previous'];

        foreach (['current', 'previous'] as $secret) {
            $event = $this->event($this->request('{}', ['X-Webhook-Secret' => $secret]));
            $this->guard($parameters)->onKernelRequest($event);
            self::assertNull($event->getResponse());
        }
        self::assertSame([], $this->logger->records);
    }

    /**
     * The secret is read from the header the settings name.
     *
     * @return void
     */
    public function testSecretHeaderCanBeChanged(): void
    {
        $parameters = ['rge_studio_connector_secret' => 'current', 'rge_studio_connector_secret_header' => 'X-Connector-Key'];
        $named      = $this->event($this->request('{}', ['X-Connector-Key' => 'current']));
        $default    = $this->event($this->request('{}', ['X-Webhook-Secret' => 'current']));

        $this->guard($parameters)->onKernelRequest($named);
        $this->guard($parameters)->onKernelRequest($default);

        self::assertNull($named->getResponse());
        self::assertSame(401, $default->getResponse()?->getStatusCode());
    }

    /**
     * A call without the secret or with a wrong one is rejected and logged with the caller's address.
     *
     * @return void
     */
    public function testCallsWithoutTheSecretAreRejected(): void
    {
        foreach ([[], ['X-Webhook-Secret' => 'wrong'], ['X-Webhook-Secret' => '']] as $headers) {
            $event = $this->event($this->request('{}', $headers));
            $this->guard(['rge_studio_connector_secret' => 'current'])->onKernelRequest($event);

            self::assertSame(401, $event->getResponse()?->getStatusCode());
            self::assertSame('{"error":"Unauthorised."}', $event->getResponse()->getContent());
        }

        self::assertCount(3, $this->logger->records);
        self::assertSame([
            'level'   => 'warning',
            'message' => 'RGE Studio connector: call rejected, wrong or missing shared secret.',
            'context' => ['ip' => '192.0.2.10'],
        ], $this->logger->records[0]);
    }

    /**
     * With no secret configured every call is rejected and the setup error is logged.
     *
     * @return void
     */
    public function testEveryCallIsRejectedWithoutAConfiguredSecret(): void
    {
        $event = $this->event($this->request('{}', ['X-Webhook-Secret' => '']));

        $this->guard(['rge_studio_connector_secret' => '', 'rge_studio_connector_previous_secret' => ''])->onKernelRequest($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame([['level' => 'error', 'message' => 'RGE Studio connector: call rejected, no shared secret is configured.', 'context' => []]], $this->logger->records);
    }

    /**
     * A declared length over the limit is rejected before the secret is read, one at the limit is not.
     *
     * @return void
     */
    public function testDeclaredLengthLimit(): void
    {
        $parameters = ['rge_studio_connector_secret' => 'current', 'rge_studio_connector_max_body_bytes' => 1024];
        $over       = $this->event($this->request('{}', ['Content-Length' => '1025']));
        $atLimit    = $this->event($this->request('{}', ['Content-Length' => '1024', 'X-Webhook-Secret' => 'current']));
        $unreadable = $this->event($this->request('{}', ['Content-Length' => 'many', 'X-Webhook-Secret' => 'current']));

        $this->guard($parameters)->onKernelRequest($over);
        $this->guard($parameters)->onKernelRequest($atLimit);
        $this->guard($parameters)->onKernelRequest($unreadable);

        self::assertSame(413, $over->getResponse()?->getStatusCode());
        self::assertSame('{"error":"The export is larger than the limit."}', $over->getResponse()->getContent());
        self::assertNull($atLimit->getResponse());
        self::assertNull($unreadable->getResponse());
    }

    /**
     * Without a declared length the real body is measured, but only after the secret is checked.
     *
     * @return void
     */
    public function testRealBodyLimit(): void
    {
        $parameters = ['rge_studio_connector_secret' => 'current', 'rge_studio_connector_max_body_bytes' => 1024];
        $atLimit    = $this->event($this->request(str_repeat(' ', 1024), ['X-Webhook-Secret' => 'current']));
        $over       = $this->event($this->request(str_repeat(' ', 1025), ['X-Webhook-Secret' => 'current']));
        $stranger   = $this->event($this->request(str_repeat(' ', 1025), ['X-Webhook-Secret' => 'wrong']));

        $this->guard($parameters)->onKernelRequest($atLimit);
        $this->guard($parameters)->onKernelRequest($over);
        $this->guard($parameters)->onKernelRequest($stranger);

        self::assertNull($atLimit->getResponse());
        self::assertSame(413, $over->getResponse()?->getStatusCode());
        self::assertSame(401, $stranger->getResponse()?->getStatusCode());
    }

    /**
     * Guard over the given settings.
     *
     * @param array<string, mixed> $parameters Settings to change
     *
     * @return WebhookGuardSubscriber
     */
    private function guard(array $parameters = []): WebhookGuardSubscriber
    {
        return new WebhookGuardSubscriber(SettingsStub::create($parameters), $this->logger);
    }

    /**
     * Kernel request event for a request.
     *
     * @param Request $request Request
     * @param int     $type    Main or sub-request
     *
     * @return RequestEvent
     */
    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    /**
     * Webhook request with headers, routed to the given route.
     *
     * @param string                $body    Request body
     * @param array<string, string> $headers Request headers
     * @param string                $route   Route the router matched
     *
     * @return Request
     */
    private function request(string $body, array $headers = [], string $route = WebhookController::ROUTE): Request
    {
        $request = new Request([], [], ['_route' => $route], [], [], ['REMOTE_ADDR' => '192.0.2.10'], $body);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
