<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use Throwable;

final class Log
{
    /** @param resource|null $stream */
    public function __construct(private mixed $stream = null) {}

    /** @param array<string, int|string> $fields */
    public function event(string $event, array $fields = []): void
    {
        $record = ['time' => gmdate('c'), 'event' => $event];
        foreach ($fields as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $key)) {
                $record[$key] = $value;
            }
        }
        fwrite($this->stream ?? STDOUT, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    public function failure(string $event, Throwable $failure): void
    {
        $this->event($event, ['type' => $failure::class]);
    }
}
