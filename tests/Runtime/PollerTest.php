<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Application\Kernel;
use Broadcast\Application\TelegramGateway;
use Broadcast\Infrastructure\Runtime\Poller;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class PollerTest extends TestCase
{
    public function testPollerAdvancesOffsetOnlyAfterHandlingUpdates(): void
    {
        $store = new SqliteStore(':memory:');
        $store->migrate();
        $telegram = $this->createMock(TelegramGateway::class);
        $offsets = [];
        $telegram->method('getUpdates')->willReturnCallback(function (int $offset) use (&$offsets): array {
            $offsets[] = $offset;
            return [['update_id' => 10, 'message' => [
                'from' => ['id' => 99], 'chat' => ['id' => 99, 'type' => 'private'], 'text' => 'News',
            ]]];
        });
        $poller = new Poller($store, $telegram, new Kernel($store, [99]));
        $poller->once();
        $poller->once();
        self::assertSame([0, 11], $offsets);
        self::assertSame('News', $store->broadcast(1)['text']);
        self::assertNull($store->broadcast(2));
    }
}
