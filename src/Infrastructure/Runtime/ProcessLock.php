<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use RuntimeException;

final class ProcessLock
{
    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function acquire(string $directory, string $role): self
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $role)) {
            throw new RuntimeException('Invalid process role.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create runtime directory.');
        }

        $handle = fopen($directory . DIRECTORY_SEPARATOR . $role . '.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open process lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(sprintf('Another %s process is already running.', $role));
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return new self($handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
