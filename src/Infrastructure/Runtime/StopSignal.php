<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

final class StopSignal
{
    public bool $requested = false;

    public function __construct()
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function (): void { $this->requested = true; });
            }
        }
    }

    public function pause(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$this->requested && microtime(true) < $deadline) {
            usleep((int) (min(0.2, max(0, $deadline - microtime(true))) * 1000000));
        }
    }
}
