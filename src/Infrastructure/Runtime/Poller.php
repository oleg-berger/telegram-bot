<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use Broadcast\Application\Kernel;
use Broadcast\Application\TelegramGateway;
use Broadcast\Infrastructure\SqliteStore;

final readonly class Poller
{
    public function __construct(private SqliteStore $store, private TelegramGateway $telegram, private Kernel $kernel) {}

    public function once(int $timeout = 25): int
    {
        $updates = $this->telegram->getUpdates($this->store->offset(), $timeout);
        foreach ($updates as $update) {
            $this->kernel->handle($update);
        }
        return count($updates);
    }
}
