<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use DateTimeImmutable;

final class DailyBackup
{
    private int $nextCheck = 0;

    public function __construct(
        private string $databasePath,
        private string $backupDirectory,
        private string $runtimeDirectory,
    ) {}

    public function runIfDue(?int $now = null): bool
    {
        $now ??= time();
        if ($now < $this->nextCheck) { return false; }
        // A failed backup is tried again in a minute, without blocking message delivery.
        $this->nextCheck = $now + 60;
        $lock = ProcessLock::acquire($this->runtimeDirectory, 'backup');
        try {
            $day = gmdate('Y-m-d', $now);
            $marker = $this->runtimeDirectory . '/backup-day';
            if (is_file($marker) && trim(file_get_contents($marker)) === $day) { return false; }
            (new SqliteBackup())->create($this->databasePath, $this->backupDirectory, new DateTimeImmutable('@' . $now));
            file_put_contents($marker . '.tmp', $day, LOCK_EX);
            if (!rename($marker . '.tmp', $marker)) { throw new \RuntimeException('Cannot save backup schedule.'); }
            return true;
        } finally {
            $lock->release();
        }
    }
}
