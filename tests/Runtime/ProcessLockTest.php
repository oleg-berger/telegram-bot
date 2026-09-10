<?php

declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\ProcessLock;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class ProcessLockTest extends TestCase
{
    public function testPreventsASecondProcessInTheSameRoleUntilReleased(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'broadcast-lock-' . bin2hex(random_bytes(8));
        $first = ProcessLock::acquire($directory, 'poller');

        try {
            try {
                ProcessLock::acquire($directory, 'poller');
                self::fail('A duplicate role acquired the lock.');
            } catch (RuntimeException $exception) {
                self::assertSame('Another poller process is already running.', $exception->getMessage());
            }

            $worker = ProcessLock::acquire($directory, 'worker');
            $worker->release();
        } finally {
            $first->release();
        }

        $next = ProcessLock::acquire($directory, 'poller');
        $next->release();
        @unlink($directory . DIRECTORY_SEPARATOR . 'poller.lock');
        @unlink($directory . DIRECTORY_SEPARATOR . 'worker.lock');
        @rmdir($directory);
    }
}
