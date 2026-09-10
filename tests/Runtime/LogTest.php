<?php

declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\Log;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    public function testFailureLogsOnlyTheExceptionType(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Log($stream);
        $log->failure('poll_failed', new RuntimeException('secret-token-value'));
        rewind($stream);
        $record = stream_get_contents($stream);

        self::assertStringContainsString('RuntimeException', $record);
        self::assertStringNotContainsString('secret-token-value', $record);
        self::assertStringNotContainsString('trace', strtolower($record));
    }
}
