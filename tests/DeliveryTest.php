<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\MailGateway;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    private SqliteStore $store;
    private array $sent = [];
    private array $mail = [];
    private array $translated = [];
    private bool $failMail = false;
    private bool $blocked = false;
    private Worker $worker;
    private int $now = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->now = time();
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(function ($id, $text): void {
            if ($id === 1 && $this->blocked) { throw new ApiFailure('blocked', blocked: true); }
            $this->sent[] = [$id, $text];
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(function ($text, $language): string {
            $this->translated[] = [$text, $language];
            return $language . ':' . $text;
        });
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(function ($to, $subject, $text): void {
            if ($this->failMail) { throw new ApiFailure('SMTP failure'); }
            $this->mail[] = [$to, $subject, $text];
        });
        $this->worker = new Worker($this->store, $telegram, $translator, null, $mail);
    }

    private function user(int $id, string $language = 'RU'): void
    {
        $this->store->saveUser(['id' => $id, 'language' => $language, 'step' => 'review', 'name' => 'Name', 'company' => 'Company', 'country' => 'Country', 'phone' => '+44770090000' . $id, 'email' => "user$id@example.com", 'status' => 'approved', 'subscribed' => 1, 'completed' => 1, 'choosing_language' => 0]);
    }

    private function broadcast(): int
    {
        return $this->store->transaction(function (): int {
            $draft = $this->store->createDraft(99, 'News');
            $this->store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Subject', 'status' => 'pending']);
            return $this->store->approveDraft($draft['id'], $draft['version'] + 1, 100)['id'];
        });
    }

    private function drain(): void
    {
        for ($i = 0; $i < 200 && $this->worker->tick($this->now += 60); $i++) {}
        self::assertLessThan(200, $i);
    }

    public function testChannelsAreIndependentAndTranslationsAreReused(): void
    {
        $this->user(1);
        $this->user(2);
        $this->broadcast();
        $this->drain();
        self::assertCount(2, $this->translated);
        self::assertCount(2, $this->mail);
        self::assertSame(['user1@example.com', 'RU:Subject', 'RU:News'], $this->mail[0]);
        self::assertCount(1, array_filter($this->sent, fn ($v) => $v === [1, 'RU:News']));
    }

    public function testRetryOnlyFailedEmailAndReportWaits(): void
    {
        $this->user(1);
        $id = $this->broadcast();
        $this->failMail = true;
        $this->drain();
        self::assertTrue($this->store->retryBroadcast($id));
        $this->failMail = false;
        $this->drain();
        self::assertCount(1, $this->mail);
        self::assertCount(1, array_filter($this->sent, fn ($v) => $v === [1, 'RU:News']));
        self::assertFalse($this->store->retryBroadcast($id));
    }

    public function testBlockedTelegramStillReceivesEmail(): void
    {
        $this->user(1);
        $this->blocked = true;
        $this->broadcast();
        $this->drain();
        self::assertCount(1, $this->mail);
        self::assertSame(0, $this->store->user(1)['subscribed']);
        self::assertSame('approved', $this->store->user(1)['status']);
    }

    public function testCommentTranslationDoesNotCrashWorker(): void
    {
        $this->store->queueComment(1, 'FR', 'Please correct the name', 'Correction', 100);
        $this->drain();
        self::assertContains([1, "Correction\n\nFR:Please correct the name"], $this->sent);
    }

    public function testCatchupHasBothChannelsWithoutDuplicateSuccessfulDeliveries(): void
    {
        $id = $this->broadcast();
        $this->drain();
        $this->user(1, 'FR');
        self::assertTrue($this->store->addCatchup(1, 'FR'));
        $this->failMail = true;
        $this->drain();
        self::assertTrue($this->store->retryBroadcast($id));
        $this->store->refreshBroadcasts();
        self::assertSame(0, $this->store->broadcast($id)['reported']);
        $this->failMail = false;
        $this->drain();
        $this->store->addCatchup(1, 'FR');
        $this->drain();
        self::assertCount(1, $this->mail);
        self::assertCount(1, array_filter($this->sent, fn ($v) => $v === [1, 'FR:News']));
    }
}
