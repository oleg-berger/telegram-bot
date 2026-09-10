<?php

declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\SqliteBackup;
use Broadcast\Infrastructure\SqliteStore;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SqliteBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'broadcast-backup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) { unlink($file); }
        }
        @rmdir($this->directory . DIRECTORY_SEPARATOR . 'backups');
        @rmdir($this->directory);
    }

    public function testOnlineBackupCanRestoreApplicationData(): void
    {
        $database = $this->directory . DIRECTORY_SEPARATOR . 'live.sqlite';
        $backupDirectory = $this->directory . DIRECTORY_SEPARATOR . 'backups';
        $store = new SqliteStore($database);
        $store->migrate();
        $store->transaction(function () use ($store): void {
            $store->saveUser([
                'id' => 17, 'language' => 'FR', 'step' => 'complete', 'name' => 'Marie',
                'company' => 'Exemple', 'country' => 'France', 'phone' => '+33123456789',
                'subscribed' => 1, 'completed' => 1, 'choosing_language' => 0,
            ]);
        });

        $backup = (new SqliteBackup())->create(
            $database,
            $backupDirectory,
            new DateTimeImmutable('2026-09-08T12:34:56.123456Z'),
        );
        unset($store);
        unlink($database);

        (new SqliteBackup())->restore($backup, $database);
        $restored = new SqliteStore($database);
        $restored->migrate();

        self::assertSame('Marie', $restored->user(17)['name']);
        self::assertSame('FR', $restored->user(17)['language']);
        self::assertStringContainsString('20260908-123456-123456', basename($backup));
    }

    public function testBackupNeverOverwritesTheLiveDatabase(): void
    {
        $database = $this->directory . DIRECTORY_SEPARATOR . 'live.sqlite';
        (new SqliteStore($database))->migrate();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup destination must differ from the live database.');
        (new SqliteBackup())->create($database, $database);
    }

    public function testRetentionNeverDeletesTheLiveDatabaseEvenIfItsNameLooksLikeABackup(): void
    {
        $database = $this->directory . DIRECTORY_SEPARATOR . 'broadcast-20000101-000000-000000.sqlite';
        (new SqliteStore($database))->migrate();
        touch($database, 1);

        (new SqliteBackup())->create(
            $database,
            $this->directory,
            new DateTimeImmutable('2026-09-08T12:34:56Z'),
        );

        self::assertFileExists($database);
    }
}
