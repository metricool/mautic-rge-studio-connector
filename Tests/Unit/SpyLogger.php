<?php

declare(strict_types=1);

namespace MauticPlugin\RgeStudioConnectorBundle\Tests\Unit;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * Keep the record instead of writing it anywhere.
     *
     * The message is not typed, so the logger fits both the older and the newer PSR-3 interface.
     *
     * @param mixed        $level   Log level
     * @param mixed        $message Message
     * @param array<mixed> $context Context values
     *
     * @return void
     */
    public function log($level, mixed $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => is_string($level) ? $level : '',
            'message' => is_string($message) || $message instanceof \Stringable ? (string) $message : '',
            'context' => $context,
        ];
    }
}
