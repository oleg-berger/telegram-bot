<?php
declare(strict_types=1);

namespace Broadcast\Tests\Export;

use Broadcast\Infrastructure\Export\XlsxUsers;
use PHPUnit\Framework\TestCase;

final class XlsxUsersTest extends TestCase
{
    public function testExportWritesTextCellsWithFiltersAndFrozenHeader(): void
    {
        $path = (new XlsxUsers())->export([
            ['id' => 42, 'name' => 'Jane', 'country' => 'France', 'company' => '=HYPERLINK()', 'phone' => '+447700900123', 'email' => 'j@example.com', 'language' => 'EN-GB', 'status' => 'approved', 'submitted_at' => 1758000000, 'approved_at' => null],
        ]);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        } finally {
            unlink($path);
        }
        // User text stays text: inline string cells cannot run Excel formulas.
        self::assertStringContainsString('<t>=HYPERLINK()</t>', $sheet);
        self::assertStringContainsString('<t>+447700900123</t>', $sheet);
        self::assertStringNotContainsString('<f>', $sheet);
        self::assertStringContainsString('<autoFilter ref="A1:J2"/>', $sheet);
        self::assertStringContainsString('state="frozen"', $sheet);
        self::assertStringContainsString('<t>2025-09-16 05:20:00</t>', $sheet);
        self::assertStringContainsString('<t>Telegram ID</t>', $sheet);
    }
}
