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
     * @param mixed              $level   Log level
     * @param string|\Stringable $message Message
     * @param array<mixed>       $context Context values
     *
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : '', 'message' => (string) $message, 'context' => $context];
    }
}
