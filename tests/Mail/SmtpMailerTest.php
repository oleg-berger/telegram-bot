<?php
declare(strict_types=1);

namespace Broadcast\Tests\Mail;

use Broadcast\Infrastructure\Mail\SmtpMailer;
use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    public function testAssignedPhpmailerPropertiesExist(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Mail/SmtpMailer.php');
        preg_match_all('/\$mail->([A-Za-z]+)\s*=/', $source, $matches);
        self::assertNotEmpty($matches[1]);
        $mail = new PHPMailer();
        foreach (array_unique($matches[1]) as $property) {
            // A misspelled assignment creates a deprecated dynamic property and crashes the worker.
            self::assertTrue(property_exists($mail, $property), sprintf('PHPMailer has no property $%s.', $property));
        }
    }

    public function testPlainEncryptionIsRejectedOutsideTests(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpMailer('localhost', 25, 'none', '', '', 'bot@example.com', 'Bot', '');
    }

    public function testPlainEncryptionIsAllowedOnlyForTests(): void
    {
        $mailer = new SmtpMailer('localhost', 25, 'none', '', '', 'bot@example.com', 'Bot', '', true);
        self::assertInstanceOf(SmtpMailer::class, $mailer);
    }

    public function testUnknownEncryptionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmtpMailer('localhost', 25, 'ssl', 'u', 'p', 'bot@example.com', 'Bot', '');
    }
}
