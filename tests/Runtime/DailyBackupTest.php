<?php
declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\DailyBackup;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class DailyBackupTest extends TestCase
{
    public function testRunsOncePerUtcDayEvenAfterRestart(): void
    {
        $dir = sys_get_temp_dir() . '/daily-backup-' . bin2hex(random_bytes(8));
        mkdir($dir);
        $path = $dir . '/live.sqlite';
        (new SqliteStore($path))->migrate();
        try {
            $now = time();
            $backup = new DailyBackup($path, $dir . '/backups', $dir . '/runtime');
            self::assertTrue($backup->runIfDue($now));
            self::assertFalse($backup->runIfDue($now));
            $restarted = new DailyBackup($path, $dir . '/backups', $dir . '/runtime');
            self::assertFalse($restarted->runIfDue($now));
            self::assertTrue($restarted->runIfDue($now + 86400));
            self::assertCount(2, glob($dir . '/backups/*.sqlite'));
        } finally {
            foreach (['backups', 'runtime'] as $folder) {
                foreach (glob($dir . '/' . $folder . '/*') ?: [] as $file) { unlink($file); }
                if (is_dir($dir . '/' . $folder)) { rmdir($dir . '/' . $folder); }
            }
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($dir);
        }
    }
}
