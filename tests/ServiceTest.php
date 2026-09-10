<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
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
        $this->bot = new Kernel($this->store, [99]);
    }

    private function say(int $user, string $text, array $extra = []): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => array_replace([
            'from' => ['id' => $user], 'chat' => ['id' => $user, 'type' => 'private'], 'text' => $text,
        ], $extra)]);
    }

    private function language(int $user, string $language): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'callback_query' => [
            'id' => 'callback-' . $this->update, 'from' => ['id' => $user],
            'message' => ['chat' => ['id' => $user, 'type' => 'private']], 'data' => 'language:' . $language,
        ]]);
    }

    private function register(int $user, string $language = 'RU'): void
    {
        $this->say($user, '/start');
        $this->language($user, $language);
        $this->say($user, 'Jane');
        $this->say($user, 'Example');
        $this->say($user, 'France');
        $this->say($user, '+33 (6) 12-34-56-78');
    }

    private function drain(?FakeTelegram $telegram = null, ?FakeTranslator $translator = null): array
    {
        $telegram ??= new FakeTelegram();
        $translator ??= new FakeTranslator();
        $worker = new Worker($this->store, $telegram, $translator);
        for ($i = 0; $i < 500 && $worker->tick(time() + 10000); $i++) {}
        self::assertLessThan(500, $i, 'Queue must settle.');
        return [$telegram, $translator];
    }

    public function testRegistrationPersistsAndRejectsInvalidPhoneInEveryLanguage(): void
    {
        foreach (['RU', 'EN-GB', 'ES', 'FR'] as $i => $language) {
            $id = $i + 1;
            $this->say($id, '/start');
            $this->language($id, $language);
            $this->say($id, '   ');
            self::assertSame('name', $this->store->user($id)['step']);
            $this->bot = new Kernel($this->store, [99]);
            $this->say($id, '/start');
            foreach (['Name', 'Company', 'Country'] as $value) { $this->say($id, $value); }
            $this->say($id, '123');
            self::assertSame('phone', $this->store->user($id)['step']);
            $this->say($id, '+44 7700 900123');
            self::assertSame(1, $this->store->user($id)['subscribed']);
            self::assertSame($language, $this->store->user($id)['language']);
            self::assertSame('+447700900123', $this->store->user($id)['phone']);
        }
    }

    public function testForeignContactIsRejectedAndOwnContactAccepted(): void
    {
        $this->say(1, '/start'); $this->language(1, 'RU');
        foreach (['Name', 'Company', 'Country'] as $value) { $this->say(1, $value); }
        $this->say(1, '', ['contact' => ['user_id' => 2, 'phone_number' => '447700900123']]);
        self::assertSame('phone', $this->store->user(1)['step']);
        $this->say(1, '', ['contact' => ['user_id' => 1, 'phone_number' => '447700900123']]);
        self::assertSame('+447700900123', $this->store->user(1)['phone']);
    }

    public function testOnlyAdminTextCreatesBroadcastAndDuplicateUpdateIsIgnored(): void
    {
        $this->say(1, 'hello'); $this->say(99, '/help');
        $this->say(99, '', ['photo' => [['file_id' => 'x']]]);
        $this->say(99, 'group', ['chat' => ['id' => -123, 'type' => 'group']]);
        self::assertNull($this->store->broadcast(1));
        $this->say(99, 'News');
        $id = $this->update;
        $this->bot->handle(['update_id' => $id, 'message' => ['from' => ['id' => 99], 'chat' => ['id' => 99, 'type' => 'private'], 'text' => 'News']]);
        $this->bot->handle(['update_id' => ++$this->update, 'edited_message' => ['text' => 'Edit']]);
        self::assertSame('News', $this->store->broadcast(1)['text']);
        self::assertNull($this->store->broadcast(2));
        self::assertNull($this->store->user(99));
    }

    public function testTranslationIsSharedAndRecipientLanguageIsFrozen(): void
    {
        $this->register(1, 'FR'); $this->register(2, 'FR'); $this->register(3, 'ES');
        $this->drain();
        $this->say(99, 'News'); $this->say(1, '/language'); $this->language(1, 'RU');
        [$telegram, $translator] = $this->drain();
        self::assertSame(['FR', 'ES'], $translator->languages);
        self::assertContains([1, 'FR:News'], $telegram->messages);
        self::assertContains([2, 'FR:News'], $telegram->messages);
        self::assertContains([3, 'ES:News'], $telegram->messages);
    }

    public function testNewSubscriberGetsLatestOnceAndCanUnsubscribe(): void
    {
        $this->say(99, 'Latest'); $this->drain();
        $this->register(1, 'EN-GB');
        [$telegram] = $this->drain();
        self::assertContains([1, 'EN-GB:Latest'], $telegram->messages);
        $this->say(1, '/start'); $this->say(1, '/stop');
        self::assertSame(0, $this->store->user(1)['subscribed']);
        $this->say(1, '/start');
        [$telegram] = $this->drain();
        self::assertNotContains([1, 'EN-GB:Latest'], $telegram->messages);
        self::assertSame(1, $this->store->user(1)['subscribed']);
    }

    public function testOptOutAfterSnapshotSkipsDelivery(): void
    {
        $this->register(1); $this->drain();
        $this->say(99, 'News'); $this->say(1, '/stop');
        [$telegram] = $this->drain();
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
    }

    public function testPermanentTranslationFailurePreventsOriginalDeliveryAndCanRetry(): void
    {
        $this->register(1); $this->drain(); $this->say(99, 'News');
        $translator = new FakeTranslator(); $translator->failure = new ApiFailure('quota');
        [$telegram] = $this->drain(null, $translator);
        self::assertNotContains([1, 'News'], $telegram->messages);
        self::assertSame('failed', $this->store->broadcast(1)['status']);
        $this->say(99, '/retry 1');
        [$telegram] = $this->drain();
        self::assertContains([1, 'RU:News'], $telegram->messages);
    }

    public function testBlockedSubscriberIsDisabled(): void
    {
        $this->register(1); $this->drain(); $this->say(99, 'News');
        $telegram = new FakeTelegram(); $telegram->blockedChat = 1;
        $this->drain($telegram);
        self::assertSame(0, $this->store->user(1)['subscribed']);
    }

    public function testLongUnicodeTextIsSplitWithoutLoss(): void
    {
        $this->register(1); $this->drain();
        $text = str_repeat('🙂Привет! ', 1100);
        $this->say(99, $text);
        [$telegram] = $this->drain();
        $parts = array_column(array_values(array_filter($telegram->messages, fn ($m) => $m[0] === 1)), 1);
        self::assertGreaterThan(1, count($parts));
        self::assertSame('RU:' . $text, implode('', $parts));
        foreach ($parts as $part) { self::assertLessThanOrEqual(4096, strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')) / 2); }
    }

    public function testTemporaryFailureHonoursRetryAfterAndStopsAfterFiveAttempts(): void
    {
        $this->register(1); $this->drain(); $this->say(99, 'News');
        $telegram = new FakeTelegram();
        $translator = new FakeTranslator();
        $translator->failure = new ApiFailure('busy', true, 120);
        $worker = new Worker($this->store, $telegram, $translator);
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

    public function testRetryDoesNotResendSuccessfulRecipients(): void
    {
        $this->register(1); $this->register(2); $this->drain(); $this->say(99, 'News');
        $telegram = new FakeTelegram(); $telegram->failingChat = 2;
        $this->drain($telegram);
        self::assertContains([1, 'RU:News'], $telegram->messages);
        $this->say(99, '/retry 1');
        [$telegram, $translator] = $this->drain();
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertContains([2, 'RU:News'], $telegram->messages);
        self::assertCount(0, $translator->languages);
    }

    public function testDeliveryResumesAtUnsentChunkAfterWorkerRestart(): void
    {
        $this->register(1); $this->drain();
        $text = str_repeat('A', 6000);
        $this->say(99, $text);
        $telegram = new FakeTelegram(); $translator = new FakeTranslator();
        $worker = new Worker($this->store, $telegram, $translator);
        while (count(array_filter($telegram->messages, fn ($m) => $m[0] === 1)) === 0) { $worker->tick(); }
        [$restarted] = $this->drain();
        $parts = array_column(array_values(array_filter(array_merge($telegram->messages, $restarted->messages), fn ($m) => $m[0] === 1)), 1);
        self::assertCount(2, $parts);
        self::assertSame('RU:' . $text, implode('', $parts));
    }

    public function testEntireBroadcastWaitsUntilAllLanguagesAreReady(): void
    {
        $this->register(1, 'RU'); $this->register(2, 'FR'); $this->drain();
        $this->say(99, 'News');
        $telegram = new FakeTelegram(); $translator = new FakeTranslator();
        $worker = new Worker($this->store, $telegram, $translator);
        while (count($translator->languages) < 1) { $worker->tick(); }
        $translator->failure = new ApiFailure('temporarily unavailable', true, 120);
        while ($worker->tick()) {}
        self::assertNotContains([1, 'RU:News'], $telegram->messages);
        self::assertSame('preparing', $this->store->broadcast(1)['status']);
    }

    public function testDatabaseReopenRetainsRegistrationAndUpdateReceipt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broadcast-test-');
        try {
            $this->store = new SqliteStore($path); $this->store->migrate();
            $this->bot = new Kernel($this->store, [99]);
            $this->say(1, '/start'); $this->language(1, 'ES'); $this->say(1, 'Jane');
            $reopened = new SqliteStore($path); $reopened->migrate();
            self::assertSame('company', $reopened->user(1)['step']);
            self::assertSame($this->update + 1, $reopened->offset());
            $this->bot = new Kernel($reopened, [99]);
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
