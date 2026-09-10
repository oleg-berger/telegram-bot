<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SQLite3;

final class SqliteBackup
{
    public function create(
        string $databasePath,
        string $backupDirectory,
        ?DateTimeImmutable $now = null,
        int $retentionDays = 7,
        ?string $preservePath = null,
    ): string {
        if (!is_file($databasePath)) {
            throw new InvalidArgumentException('Live database does not exist.');
        }
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Backup retention must be positive.');
        }
        if ($this->samePath($databasePath, $backupDirectory)) {
            throw new InvalidArgumentException('Backup destination must differ from the live database.');
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
            throw new RuntimeException('Cannot create backup directory.');
        }

        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $stem = 'broadcast-' . $now->format('Ymd-His-u');
        $destination = $backupDirectory . DIRECTORY_SEPARATOR . $stem . '.sqlite';
        for ($suffix = 1; is_file($destination); $suffix++) {
            $destination = $backupDirectory . DIRECTORY_SEPARATOR . $stem . '-' . $suffix . '.sqlite';
        }
        if ($this->samePath($databasePath, $destination)) {
            throw new InvalidArgumentException('Backup destination must differ from the live database.');
        }

        $source = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
        $target = new SQLite3($destination, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $complete = false;
        try {
            if (!$source->backup($target)) {
                throw new RuntimeException('SQLite backup failed.');
            }
            if ($target->querySingle('PRAGMA integrity_check') !== 'ok') {
                throw new RuntimeException('SQLite backup integrity check failed.');
            }
            $complete = true;
        } finally {
            $target->close();
            $source->close();
            if (!$complete && is_file($destination)) {
                unlink($destination);
            }
        }

        chmod($destination, 0600);
        $this->prune($backupDirectory, $now->getTimestamp() - ($retentionDays * 86400), $databasePath, $preservePath);

        return $destination;
    }

    public function restore(string $backupPath, string $databasePath): void
    {
        if (!is_file($backupPath)) {
            throw new InvalidArgumentException('Backup file does not exist.');
        }
        if ($this->samePath($backupPath, $databasePath)) {
            throw new InvalidArgumentException('Restore source must differ from the live database.');
        }
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create database directory.');
        }

        $source = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
        if ($source->querySingle('PRAGMA integrity_check') !== 'ok') {
            $source->close();
            throw new RuntimeException('Backup integrity check failed.');
        }
        $target = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        try {
            if (!$source->backup($target)) {
                throw new RuntimeException('SQLite restore failed.');
            }
        } finally {
            $target->close();
            $source->close();
        }
        chmod($databasePath, 0600);
    }

    private function prune(string $directory, int $cutoff, string $liveDatabase, ?string $preservePath): void
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            return;
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'broadcast-*.sqlite') ?: [] as $path) {
            if (is_link($path) || !is_file($path) || !preg_match('/^broadcast-[0-9]{8}-[0-9]{6}-[0-9]{6}(?:-[0-9]+)?\.sqlite$/D', basename($path))) {
                continue;
            }
            $realPath = realpath($path);
            if ($realPath === false || dirname($realPath) !== $realDirectory || $this->samePath($realPath, $liveDatabase)) {
                continue;
            }
            if ($preservePath !== null && $this->samePath($realPath, $preservePath)) {
                continue;
            }
            $modified = filemtime($realPath);
            if ($modified !== false && $modified < $cutoff) {
                unlink($realPath);
            }
        }
    }

    private function samePath(string $left, string $right): bool
    {
        $leftReal = realpath($left);
        $rightReal = realpath($right);
        if ($leftReal !== false && $rightReal !== false) {
            return $leftReal === $rightReal;
        }

        return $this->normalize($left) === $this->normalize($right);
    }

    private function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('~^[A-Za-z]:\\\\~', $path)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}
