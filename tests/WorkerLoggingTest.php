<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class WorkerLoggingTest extends TestCase
{
    public function testLogContainsJobStatusButNoMessageOrPersonalData(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $store->queueReply(12345, 'PRIVATE PHONE +447700900123');
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willThrowException(new ApiFailure('SECRET TOKEN'));
        $translator = $this->createStub(Translator::class);
        $events = [];
        $worker = new Worker($store, $telegram, $translator, static function (array $event) use (&$events): void { $events[] = $event; });
        $worker->tick();
        self::assertSame([['event' => 'job_failed', 'job_id' => 1, 'kind' => 'message', 'attempt' => 1]], $events);
    }
}
