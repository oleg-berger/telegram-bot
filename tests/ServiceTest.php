<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\MailGateway;
use Broadcast\Application\Worker;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    private SqliteStore $store;
    private Kernel $bot;
    private int $update = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->bot = new Kernel($this->store, [99], [100]);
    }

    private function say(int $user, string $text, array $extra = []): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => array_replace([
            'from' => ['id' => $user], 'chat' => ['id' => $user, 'type' => 'private'], 'text' => $text,
        ], $extra)]);
    }

    private function click(int $user, string $data): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'callback_query' => [
            'id' => 'callback-' . $this->update, 'from' => ['id' => $user],
            'message' => ['chat' => ['id' => $user, 'type' => 'private']], 'data' => $data,
        ]]);
    }

    private function register(int $user, string $language = 'RU'): void
    {
        $this->say($user, '/start');
        $this->click($user, 'language:' . $language);
        $this->click($user, 'reg:begin');
        $this->say($user, 'Jane Doe');
        $this->say($user, 'Example');
        $this->say($user, '+447700900' . sprintf('%03d', $user));
        $this->click($user, 'reg:country:other');
        $this->say($user, 'France');
        $this->say($user, "user$user@example.com");
        $this->click($user, 'reg:submit');
    }

    private function approve(int $user): void
    {
        $this->click(100, "app:approve:$user:" . $this->store->user($user)['revision']);
    }

    private function broadcast(string $text = 'News', string $subject = 'Subject'): void
    {
        $this->say(99, $text);
        $this->say(99, $subject);
        $draft = $this->store->draft(1);
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        $this->click(100, 'draft:approve:1:' . $this->store->draft(1)['version']);
    }

    private function drain(?FakeTelegram $telegram = null, ?FakeTranslator $translator = null, ?FakeMailer $mailer = null): array
    {
        $telegram ??= new FakeTelegram();
        $translator ??= new FakeTranslator();
        $mailer ??= new FakeMailer();
        $worker = new Worker($this->store, $telegram, $translator, null, $mailer, new FakeExporter());
        $now = time();
        $idle = 0;
        for ($i = 0; $i < 500 && $idle < 2; $i++) {
            if ($worker->tick($now += 10)) { $idle = 0; } else { $idle++; $now += 120; }
        }
        self::assertLessThan(500, $i, 'Queue must settle.');
        self::assertSame(2, $idle, 'Queue must be empty.');
        return [$telegram, $translator, $mailer];
    }

    public function testRegistrationPersistsAndRejectsInvalidInputInEveryLanguage(): void
    {
        foreach (['RU', 'EN-GB', 'ES', 'FR'] as $i => $language) {
            $id = $i + 1;
            $this->say($id, '/start');
            $this->click($id, 'language:' . $language);
            $this->click($id, 'reg:begin');
            $this->say($id, '   ');
            self::assertSame('name', $this->store->user($id)['step']);
            $this->bot = new Kernel($this->store, [99], [100]);
            $this->say($id, '/start');
            $this->say($id, 'Name');
            $this->say($id, 'Company');
            $this->say($id, '123');
            self::assertSame('phone', $this->store->user($id)['step']);
            $this->say($id, '+44 7700 900' . sprintf('%03d', $id));
            self::assertSame('+447700900' . sprintf('%03d', $id), $this->store->user($id)['phone']);
            $this->click($id, 'reg:country:other');
            $this->say($id, 'France');
            $this->say($id, "user$id@example.com");
            self::assertSame('review', $this->store->user($id)['step']);
            $this->click($id, 'reg:submit');
            self::assertSame('pending', $this->store->user($id)['status']);
            self::assertSame(0, $this->store->user($id)['subscribed']);
            self::assertSame($language, $this->store->user($id)['language']);
        }
    }

    public function testForeignContactIsRejectedAndOwnContactAccepted(): void
    {
        $this->say(1, '/start');
        $this->click(1, 'language:RU');
        $this->click(1, 'reg:begin');
        $this->say(1, 'Name');
        $this->say(1, 'Company');
        $this->say(1, '', ['contact' => ['user_id' => 2, 'phone_number' => '447700900123']]);
        self::assertSame('phone', $this->store->user(1)['step']);
        $this->say(1, '', ['contact' => ['user_id' => 1, 'phone_number' => '447700900123']]);
        self::assertSame('+447700900123', $this->store->user(1)['phone']);
        self::assertSame('country', $this->store->user(1)['step']);
    }

    public function testOnlyStaffTextCreatesDraftAndDuplicateUpdateIsIgnored(): void
    {
        $this->say(1, 'hello');
        $this->say(99, '/help');
        $this->say(99, '', ['photo' => [['file_id' => 'x']]]);
        $this->say(99, 'group', ['chat' => ['id' => -123, 'type' => 'group']]);
        self::assertNull($this->store->draft(1));
        self::assertNull($this->store->broadcast(1));
        $this->say(99, 'News');
        $id = $this->update;
        $this->bot->handle(['update_id' => $id, 'message' => ['from' => ['id' => 99], 'chat' => ['id' => 99, 'type' => 'private'], 'text' => 'News']]);
        $this->bot->handle(['update_id' => ++$this->update, 'edited_message' => ['text' => 'Edit']]);
        self::assertSame('News', $this->store->draft(1)['text']);
        self::assertNull($this->store->draft(2));
        self::assertNull($this->store->broadcast(1));
        self::assertNull($this->store->user(99));
    }

    public function testTranslationIsSharedAndRecipientLanguageIsFrozen(): void
    {
        $this->register(1, 'FR');
        $this->register(2, 'FR');
        $this->register(3, 'ES');
        $this->approve(1);
        $this->approve(2);
        $this->approve(3);
        $this->drain();
        $this->broadcast('News');
        $this->say(1, '/language');
        $this->click(1, 'language:RU');
        [$telegram, $translator, $mailer] = $this->drain();
        self::assertContains('FR', $translator->languages);
        self::assertContains('ES', $translator->languages);
        self::assertNotContains('RU', $translator->languages);
        self::assertContains([1, 'FR:News'], $telegram->messages);
        self::assertContains([2, 'FR:News'], $telegram->messages);
        self::assertContains([3, 'ES:News'], $telegram->messages);
        self::assertContains(['user1@example.com', 'FR:Subject', 'FR:News'], $mailer->mails);
        self::assertContains(['user3@example.com', 'ES:Subject', 'ES:News'], $mailer->mails);
    }

    public function testNewApprovedUserGetsLatestAnnouncementOnce(): void
    {
        $this->broadcast('Latest');
        $this->drain();
        $this->register(1, 'EN-GB');
        $this->approve(1);
        [$telegram, , $mailer] = $this->drain();
        self::assertContains([1, 'EN-GB:Latest'], $telegram->messages);
        self::assertContains(['user1@example.com', 'EN-GB:Subject', 'EN-GB:Latest'], $mailer->mails);
        $this->say(1, '/start');
        [$telegram] = $this->drain();
        self::assertNotContains([1, 'EN-GB:Latest'], $telegram->messages);
    }

    public function testBlockedUserKeepsApprovalAndEmailDelivery(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->broadcast('News');
        $telegram = new FakeTelegram();
        $telegram->blockedChat = 1;
        [, , $mailer] = $this->drain($telegram);
        self::assertSame(0, $this->store->user(1)['subscribed']);
        self::assertSame('approved', $this->store->user(1)['status']);
        self::assertContains(['user1@example.com', 'RU:Subject', 'RU:News'], $mailer->mails);
    }

    public function testCommentIsTranslatedAndOriginalNeverSubstituted(): void
    {
        $this->register(1, 'FR');
        $this->click(100, 'app:changes:1:' . $this->store->user(1)['revision']);
        $this->say(100, 'Fix your phone.');
        self::assertSame('changes', $this->store->user(1)['status']);
        [$telegram] = $this->drain();
        self::assertContains([1, "✏️ Veuillez corriger vos informations et renvoyer votre demande.\n\nFR:Fix your phone."], $telegram->messages);

        $this->click(1, 'reg:submit');
        $this->click(100, 'app:reject:1:' . $this->store->user(1)['revision']);
        $this->say(100, 'Original must not leak.');
        $translator = new FakeTranslator();
        $translator->failure = new ApiFailure('quota');
        [$telegram] = $this->drain(null, $translator);
        foreach ($telegram->messages as $message) {
            if ($message[0] === 1) { self::assertStringNotContainsString('Original must not leak.', $message[1]); }
        }
        $staff = array_column(array_values(array_filter($telegram->messages, fn ($m) => $m[0] === 100)), 1);
        $notifications = array_filter($staff, fn ($text) => str_contains($text, '(comment) не выполнено') && str_contains($text, '/retryjob'));
        self::assertNotEmpty($notifications);
    }

    public function testRetryjobRerunsFailedCommentOnlyForOwner(): void
    {
        $this->store->queueComment(1, 'FR', 'Fix your phone.', 'Prefix', 100);
        $translator = new FakeTranslator();
        $translator->failure = new ApiFailure('quota');
        $this->drain(null, $translator);
        self::assertFalse($this->store->retryAuxiliaryJob(1, 99, false));
        self::assertTrue($this->store->retryAuxiliaryJob(1, 100, false));
        self::assertFalse($this->store->retryAuxiliaryJob(1, 100, false));
        [$telegram] = $this->drain();
        self::assertContains([1, "Prefix\n\nFR:Fix your phone."], $telegram->messages);

        $this->store->queueComment(1, 'FR', 'Again.', 'Prefix', 100);
        $this->drain(null, $translator);
        $this->say(99, '/retryjob 3');
        $this->say(100, '/retryjob 3');
        [$telegram] = $this->drain();
        self::assertContains([1, "Prefix\n\nFR:Again."], $telegram->messages);
    }

    public function testPermanentTranslationFailurePreventsDeliveryAndCanRetry(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->broadcast('News');
        $translator = new FakeTranslator();
        $translator->failure = new ApiFailure('quota');
        [$telegram] = $this->drain(null, $translator);
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertSame('failed', $this->store->broadcast(1)['status']);
        $this->say(99, '/retry 1');
        [$telegram, , $mailer] = $this->drain();
        self::assertContains([1, 'RU:News'], $telegram->messages);
        self::assertContains(['user1@example.com', 'RU:Subject', 'RU:News'], $mailer->mails);
    }

    public function testEmailFailureDoesNotCancelTelegramDeliveryAndCanRetry(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->broadcast('News');
        $mailer = new FakeMailer();
        $mailer->failure = new ApiFailure('smtp down');
        [$telegram] = $this->drain(null, null, $mailer);
        self::assertContains([1, 'RU:News'], $telegram->messages);
        self::assertCount(0, $mailer->mails);
        $this->say(99, '/retry 1');
        [, , $mailer] = $this->drain();
        self::assertContains(['user1@example.com', 'RU:Subject', 'RU:News'], $mailer->mails);
    }

    public function testLongUnicodeTextIsSplitWithoutLoss(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $text = str_repeat('🙂Привет! ', 1100);
        $this->broadcast($text);
        [$telegram] = $this->drain();
        $parts = array_column(array_values(array_filter($telegram->messages, fn ($m) => $m[0] === 1)), 1);
        self::assertGreaterThan(1, count($parts));
        self::assertSame('RU:' . trim($text), implode('', $parts));
        foreach ($parts as $part) { self::assertLessThanOrEqual(4096, strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')) / 2); }
    }

    public function testTemporaryFailureHonoursRetryAfterAndStopsAfterFiveAttempts(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->broadcast('News');
        $telegram = new FakeTelegram();
        $translator = new FakeTranslator();
        $translator->failure = new ApiFailure('busy', true, 120);
        $worker = new Worker($this->store, $telegram, $translator, null, new FakeMailer(), new FakeExporter());
        $now = time();
        while ($worker->tick($now)) {}
        self::assertCount(1, $translator->languages);
        self::assertFalse($worker->tick($now + 119));
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            while ($worker->tick($now + 120 * $attempt)) {}
        }
        self::assertCount(5, $translator->languages);
        while ($worker->tick($now + 10000)) {}
        self::assertCount(5, $translator->languages);
        self::assertSame('failed', $this->store->broadcast(1)['status']);
    }

    public function testRetryDoesNotResendSuccessfulDeliveries(): void
    {
        $this->register(1);
        $this->register(2);
        $this->approve(1);
        $this->approve(2);
        $this->drain();
        $this->broadcast('News');
        $telegram = new FakeTelegram();
        $telegram->failingChat = 2;
        [$telegram, , $mailer] = $this->drain($telegram);
        self::assertContains([1, 'RU:News'], $telegram->messages);
        self::assertCount(2, $mailer->mails);
        $this->say(99, '/retry 1');
        [$telegram, $translator, $mailer] = $this->drain();
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertContains([2, 'RU:News'], $telegram->messages);
        self::assertCount(0, $translator->languages);
        self::assertCount(0, $mailer->mails);
    }

    public function testDeliveryResumesAtUnsentChunkAfterWorkerRestart(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $text = str_repeat('A', 6000);
        $this->broadcast($text);
        $telegram = new FakeTelegram();
        $translator = new FakeTranslator();
        $worker = new Worker($this->store, $telegram, $translator, null, new FakeMailer(), new FakeExporter());
        while (count(array_filter($telegram->messages, fn ($m) => $m[0] === 1)) === 0) { $worker->tick(); }
        [$restarted] = $this->drain();
        $parts = array_column(array_values(array_filter(array_merge($telegram->messages, $restarted->messages), fn ($m) => $m[0] === 1)), 1);
        self::assertCount(2, $parts);
        self::assertSame('RU:' . $text, implode('', $parts));
    }

    public function testEntireBroadcastWaitsUntilAllLanguagesAreReady(): void
    {
        $this->register(1, 'RU');
        $this->register(2, 'FR');
        $this->approve(1);
        $this->approve(2);
        $this->drain();
        $this->broadcast('News');
        $telegram = new FakeTelegram();
        $translator = new FakeTranslator();
        $worker = new Worker($this->store, $telegram, $translator, null, new FakeMailer(), new FakeExporter());
        while (count($translator->languages) < 1) { $worker->tick(); }
        $translator->failure = new ApiFailure('temporarily unavailable', true, 120);
        while ($worker->tick()) {}
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertSame('preparing', $this->store->broadcast(1)['status']);
    }

    public function testMailSendIntervalDefersEmailJobsWithoutAttempts(): void
    {
        $this->register(1);
        $this->register(2);
        $this->approve(1);
        $this->approve(2);
        $this->broadcast('News');
        $telegram = new FakeTelegram();
        $translator = new FakeTranslator();
        $mailer = new FakeMailer();
        $worker = new Worker($this->store, $telegram, $translator, null, $mailer, new FakeExporter(), 60);
        $now = time();
        while ($worker->tick($now)) {}
        self::assertCount(1, $mailer->mails);
        self::assertFalse($worker->tick($now + 59));
        while ($worker->tick($now + 60)) {}
        self::assertCount(2, $mailer->mails);
    }

    public function testUserdataExportSendsXlsxOnlyToAllowedStaff(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->say(99, '/userdata');
        [$telegram] = $this->drain();
        self::assertCount(0, $telegram->documents);
        $this->say(100, '/userdata');
        [$telegram] = $this->drain();
        self::assertCount(1, $telegram->documents);
        self::assertSame(100, $telegram->documents[0][0]);
        self::assertSame('users.xlsx', $telegram->documents[0][2]);
        self::assertStringContainsString('Jane', (string) $telegram->documents[0][1]);
    }

    public function testSuperadminCanUnsubscribeUserFromAllChannels(): void
    {
        $this->register(1);
        $this->approve(1);
        $this->drain();
        $this->say(99, '/unsubscribe 1');
        self::assertSame('approved', $this->store->user(1)['status']);
        $this->say(100, '/unsubscribe 1');
        self::assertSame('unsubscribed', $this->store->user(1)['status']);
        self::assertSame(0, $this->store->user(1)['subscribed']);
        self::assertFalse($this->store->unsubscribeUser(1));
        $this->broadcast('News');
        [$telegram, , $mailer] = $this->drain();
        self::assertContains([99, 'Отписка пользователей доступна только суперадминистратору.'], $telegram->messages);
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertCount(0, $mailer->mails);
        $this->say(1, '/start');
        $this->click(1, 'reg:submit');
        self::assertSame('pending', $this->store->user(1)['status']);
        $this->approve(1);
        [$telegram, , $mailer] = $this->drain();
        self::assertContains([1, 'RU:News'], $telegram->messages);
        self::assertContains(['user1@example.com', 'RU:Subject', 'RU:News'], $mailer->mails);
    }

    public function testDatabaseReopenRetainsRegistrationAndUpdateReceipt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broadcast-test-');
        try {
            $this->store = new SqliteStore($path);
            $this->store->migrate();
            $this->bot = new Kernel($this->store, [99], [100]);
            $this->say(1, '/start');
            $this->click(1, 'language:ES');
            $this->click(1, 'reg:begin');
            $this->say(1, 'Jane');
            $reopened = new SqliteStore($path);
            $reopened->migrate();
            self::assertSame('company', $reopened->user(1)['step']);
            self::assertSame($this->update + 1, $reopened->offset());
            $this->bot = new Kernel($reopened, [99], [100]);
            $this->say(1, '/start');
            self::assertSame('company', $reopened->user(1)['step']);
            unset($reopened);
        } finally {
            unset($this->bot, $this->store);
            gc_collect_cycles();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }
}

final class FakeTelegram implements TelegramGateway
{
    public array $messages = [];
    public array $documents = [];
    public ?int $blockedChat = null;
    public ?int $failingChat = null;
    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        if ($chatId === $this->blockedChat) { throw new ApiFailure('blocked', blocked: true); }
        if ($chatId === $this->failingChat) { throw new ApiFailure('bad request'); }
        $this->messages[] = [$chatId, $text];
    }
    public function getUpdates(int $offset, int $timeout = 25): array { return []; }
    public function answerCallbackQuery(string $id): void {}
    public function sendDocument(int $chatId, string $path, string $filename): void
    {
        $this->documents[] = [$chatId, file_get_contents($path), $filename];
    }
    public function setCommands(array $commands, array $scope = ['type' => 'default'], string $language = ''): void {}
}

final class FakeTranslator implements Translator
{
    public array $languages = [];
    public ?ApiFailure $failure = null;
    public function translate(string $text, string $targetLanguage): string
    {
        $this->languages[] = $targetLanguage;
        if ($this->failure) { throw $this->failure; }
        return $targetLanguage . ':' . $text;
    }
}

final class FakeMailer implements MailGateway
{
    public array $mails = [];
    public ?ApiFailure $failure = null;
    public function send(string $to, string $subject, string $text): void
    {
        if ($this->failure) { throw $this->failure; }
        $this->mails[] = [$to, $subject, $text];
    }
}

final class FakeExporter implements \Broadcast\Application\UserExporter
{
    public function export(array $users): string
    {
        $path = tempnam(sys_get_temp_dir(), 'broadcast-export-');
        file_put_contents($path, json_encode($users, JSON_UNESCAPED_UNICODE));
        return $path;
    }
}
