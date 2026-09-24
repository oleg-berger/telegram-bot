<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\Kernel;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Runtime\Log;
use Broadcast\Infrastructure\SqliteStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalFlowTest extends TestCase
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

    private function say(int $id, string $text, array $extra = []): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'message' => array_replace(['from' => ['id' => $id], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $text], $extra)]);
    }

    private function click(int $id, string $data): void
    {
        $this->bot->handle(['update_id' => ++$this->update, 'callback_query' => ['id' => (string) $this->update, 'from' => ['id' => $id], 'message' => ['chat' => ['id' => $id, 'type' => 'private']], 'data' => $data]]);
    }

    private function register(int $id = 1, string $language = 'RU'): void
    {
        $this->say($id, '/start');
        $this->click($id, 'language:' . $language);
        $this->click($id, 'reg:begin');
        $this->say($id, 'Jane Doe');
        $this->say($id, 'Example');
        $this->say($id, '+447700900' . sprintf('%03d', $id));
        $this->click($id, 'reg:country:other');
        $this->say($id, 'France');
        $this->say($id, "user$id@example.com");
        $this->click($id, 'reg:submit');
    }

    public function testTestResetRequiresFullRegistrationAndInvalidatesOldApproval(): void
    {
        $this->bot = new Kernel($this->store, [99], [100], testMode: true);
        $this->register();
        $oldRevision = $this->store->user(1)['revision'];
        $this->click(100, 'app:approve:1:' . $oldRevision);
        $this->say(100, '/resetuser 1');
        $resetUpdate = $this->update;
        $user = $this->store->user(1);
        foreach (['name', 'company', 'country', 'phone', 'email', 'edit_field'] as $field) {
            self::assertSame('', $user[$field]);
        }
        self::assertSame('draft', $user['status']);
        self::assertSame('language', $user['step']);
        self::assertSame(1, $user['choosing_language']);
        self::assertSame(0, $user['subscribed']);
        self::assertSame(0, $user['completed']);
        self::assertNull($user['submitted_at']);
        self::assertNull($user['approved_at']);
        self::assertSame([], $this->store->exportUsers());
        self::assertFalse($this->store->contactTaken('phone', '+447700900001', 2));
        self::assertFalse($this->store->contactTaken('email', 'USER1@example.com', 2));
        $this->bot->handle(['update_id' => $resetUpdate, 'message' => ['from' => ['id' => 100], 'chat' => ['id' => 100, 'type' => 'private'], 'text' => '/resetuser 1']]);
        self::assertSame($user, $this->store->user(1));
        $this->click(1, 'reg:submit');
        self::assertSame('draft', $this->store->user(1)['status']);
        $this->say(1, '/start');
        $this->click(1, 'language:FR');
        $this->click(1, 'reg:begin');
        self::assertSame('name', $this->store->user(1)['step']);
        $this->say(1, 'New Name');
        self::assertSame('company', $this->store->user(1)['step']);
        $this->say(1, 'New Company');
        self::assertSame('phone', $this->store->user(1)['step']);
        $this->say(1, '+447700900001');
        $this->click(1, 'reg:country:other');
        $this->say(1, 'France');
        self::assertSame('email', $this->store->user(1)['step']);
        $this->say(1, 'new@example.com');
        $this->click(1, 'reg:submit');
        self::assertSame('pending', $this->store->user(1)['status']);
        $this->click(100, 'app:approve:1:' . $oldRevision);
        self::assertSame('pending', $this->store->user(1)['status']);
        $this->click(100, 'app:approve:1:' . $this->store->user(1)['revision']);
        self::assertSame('approved', $this->store->user(1)['status']);
        self::assertSame('New Name', $this->store->user(1)['name']);
    }

    public static function forbiddenTestResets(): array
    {
        return [[false, 100], [false, 99], [true, 99], [true, 1]];
    }

    #[DataProvider('forbiddenTestResets')]
    public function testTestResetCannotBeUsedOutsideTestSuperadmin(bool $testMode, int $actor): void
    {
        $this->register();
        $this->bot = new Kernel($this->store, [99], [100], testMode: $testMode);
        $before = $this->store->user(1);
        $this->say($actor, '/resetuser 1');
        self::assertSame($before, $this->store->user(1));
        self::assertTrue($this->store->contactTaken('email', 'user1@example.com', 2));
    }

    public function testTestResetCancelsOldUserJobsAndLeavesOtherUsersAlone(): void
    {
        $this->bot = new Kernel($this->store, [99], [100], testMode: true);
        $this->store->queueComment(1, 'RU', 'Failed old comment', 'Prefix', 100);
        $failedJob = $this->store->nextJob(time());
        $this->store->deferJob($failedJob['id'], 5, 0, 'temporary_api_error', true);
        $this->register();
        $this->register(2);
        $other = $this->store->user(2);
        $this->click(100, 'app:approve:1:' . $this->store->user(1)['revision']);
        $this->say(99, 'Old announcement');
        $this->say(99, 'Subject');
        $this->click(99, 'draft:submit:1:' . $this->store->draft(1)['version']);
        $this->click(100, 'draft:approve:1:' . $this->store->draft(1)['version']);
        $this->store->queueComment(1, 'RU', 'Old comment', 'Prefix', 100);
        $this->say(100, '/resetuser 1');
        self::assertSame($other, $this->store->user(2));
        self::assertFalse($this->store->retryAuxiliaryJob($failedJob['id'], 100, true));
        self::assertTrue($this->store->contactTaken('email', 'user2@example.com', 1));
        self::assertFalse($this->store->retryBroadcast(1));
        $messages = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(static function (int $id, string $text) use (&$messages): void {
            if ($id === 1) {
                $messages[] = $text;
            }
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturn('Translation');
        $mailer = $this->createMock(\Broadcast\Application\MailGateway::class);
        $mailer->expects(self::never())->method('send');
        $worker = new Worker($this->store, $telegram, $translator, mail: $mailer);
        $now = time();
        for ($i = 0; $i < 100 && $worker->tick($now += 60); ++$i) {}
        self::assertLessThan(100, $i);
        self::assertSame([\Broadcast\Application\Messages::text('RU', 'test_reset_user')], $messages);
        $this->say(100, '/resetuser 999');
        self::assertNull($this->store->user(999));
    }

    public function testRegistrationRequiresApprovalInAllLanguages(): void
    {
        foreach (['RU', 'EN-GB', 'ES', 'FR'] as $i => $language) {
            $id = $i + 1;
            $this->register($id, $language);
            $user = $this->store->user($id);
            self::assertSame('pending', $user['status']);
            self::assertSame(0, $user['subscribed']);
            $this->click(99, "app:approve:$id:" . $user['revision']);
            self::assertSame('pending', $this->store->user($id)['status']);
            $this->click(100, "app:approve:$id:" . $user['revision']);
            self::assertSame('approved', $this->store->user($id)['status']);
            self::assertSame($language, $this->store->user($id)['language']);
        }
    }

    public function testDraftMustBeApprovedAndStaleButtonCannotSend(): void
    {
        $this->say(99, 'News');
        self::assertNull($this->store->broadcast(1));
        $this->say(99, 'Subject');
        $draft = $this->store->draft(1);
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        $draft = $this->store->draft(1);
        self::assertSame('pending', $draft['status']);
        $this->click(99, 'draft:approve:1:' . $draft['version']);
        self::assertNull($this->store->broadcast(1));
        $this->click(100, 'draft:approve:1:' . ($draft['version'] - 1));
        self::assertNull($this->store->broadcast(1));
        $this->click(100, 'draft:approve:1:' . $draft['version']);
        self::assertSame('News', $this->store->broadcast(1)['text']);
        $this->click(100, 'draft:approve:1:' . $draft['version']);
        self::assertNull($this->store->broadcast(2));
    }

    public static function draftAuthors(): array
    {
        return [
            'username takes precedence' => [['username' => 'news_editor', 'first_name' => 'Jane', 'last_name' => 'Doe'], '@news_editor', false],
            'full name' => [['first_name' => 'Jane', 'last_name' => 'Doe'], 'Jane Doe', false],
            'first name only' => [['first_name' => 'Jane'], 'Jane', false],
            'ID fallback' => [[], '99', false],
            'failed delivery keeps author out of logs' => [['username' => 'news_editor', 'first_name' => 'Jane'], '@news_editor', true],
        ];
    }

    #[DataProvider('draftAuthors')]
    public function testSubmittedDraftShowsAuthorWithoutLoggingProfile(array $from, string $author, bool $fail): void
    {
        $this->say(99, 'News');
        $this->say(99, 'Subject');
        $draft = $this->store->draft(1);
        $update = ['update_id' => ++$this->update, 'callback_query' => [
            'id' => (string) $this->update,
            'from' => ['id' => 99] + $from,
            'message' => ['from' => ['id' => 999, 'username' => 'bot_account'], 'chat' => ['id' => 99, 'type' => 'private']],
            'data' => 'draft:submit:1:' . $draft['version'],
        ]];
        $this->bot->handle($update);
        $this->bot->handle($update);
        self::assertSame('pending', $this->store->draft(1)['status']);
        self::assertNull($this->store->broadcast(1));

        $previews = [];
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->expects(self::exactly(4))->method('sendMessage')->willReturnCallback(
            static function (int $id, string $text, array $options) use (&$previews, $fail): void {
                if ($id === 100) {
                    $previews[] = $text;
                    if ($fail) {
                        throw new ApiFailure($text);
                    }
                }
            }
        );
        $telegram->expects(self::once())->method('answerCallbackQuery');
        $translator = $this->createMock(Translator::class);
        $translator->expects(self::never())->method('translate');
        $stream = fopen('php://memory', 'w+');
        $log = new Log($stream);
        $worker = new Worker($this->store, $telegram, $translator, static function (array $event) use ($log): void {
            $name = $event['event'];
            unset($event['event']);
            $log->event($name, $event);
        });
        try {
            $ticks = 0;
            while ($ticks < 20 && $worker->tick(time())) {
                ++$ticks;
            }
            self::assertSame(5, $ticks);
            self::assertCount(1, $previews);
            self::assertStringContainsString("\nАвтор: $author\nТема: Subject\n\nNews", $previews[0]);
            self::assertStringNotContainsString('bot_account', $previews[0]);
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertStringContainsString($fail ? 'job_failed' : 'job_processed', $output);
            foreach (['news_editor', 'Jane', 'Doe', 'bot_account', 'Subject', 'News', 'Автор'] as $privateText) {
                self::assertStringNotContainsString($privateText, $output);
            }
            foreach (explode("\n", trim($output)) as $line) {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame([], array_diff(array_keys($record), ['time', 'event', 'job_id', 'kind', 'attempt']));
            }
        } finally {
            fclose($stream);
        }
    }

    public function testRejectedApplicationCannotBypassApprovalWithStart(): void
    {
        $this->register();
        $user = $this->store->user(1);
        $this->click(100, 'app:reject:1:' . $user['revision']);
        $this->say(100, 'Please contact your manager.');
        self::assertSame('rejected', $this->store->user(1)['status']);
        $this->say(1, '/start');
        $this->click(1, 'reg:submit');
        self::assertSame('rejected', $this->store->user(1)['status']);
    }

    public function testReturnedDraftIsEditedAndResubmittedWithNewVersion(): void
    {
        $this->say(99, 'News');
        $this->say(99, 'Subject');
        $draft = $this->store->draft(1);
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        $draft = $this->store->draft(1);
        $this->click(100, 'draft:return:1:' . $draft['version']);
        $this->say(100, 'Make it shorter.');
        $draft = $this->store->draft(1);
        self::assertSame('changes', $draft['status']);
        $this->click(99, 'draft:submit:1:' . ($draft['version'] - 1));
        self::assertSame('changes', $this->store->draft(1)['status']);
        $this->click(99, 'draft:text:1:' . $draft['version']);
        $this->say(99, 'Short news');
        $draft = $this->store->draft(1);
        self::assertSame('Short news', $draft['text']);
        $this->click(99, 'draft:submit:1:' . $draft['version']);
        self::assertSame('pending', $this->store->draft(1)['status']);
        $this->click(100, 'draft:approve:1:' . $this->store->draft(1)['version']);
        self::assertSame('Short news', $this->store->broadcast(1)['text']);
        self::assertSame('Subject', $this->store->broadcast(1)['subject']);
    }

    public function testDuplicateContactsAndBackNavigation(): void
    {
        $this->register();
        $this->say(2, '/start');
        $this->click(2, 'language:FR');
        $this->click(2, 'reg:begin');
        $this->say(2, 'Other');
        $this->say(2, 'Company');
        $this->say(2, '+44 7700 900001');
        self::assertSame('phone', $this->store->user(2)['step']);
        $this->say(2, '+447700900002');
        $this->click(2, 'reg:country:other');
        $this->say(2, 'France');
        $this->say(2, 'USER1@example.com');
        self::assertSame('email', $this->store->user(2)['step']);
        $this->say(2, 'other@example.com');
        self::assertSame('review', $this->store->user(2)['step']);
        $this->click(2, 'reg:back');
        self::assertSame('email', $this->store->user(2)['step']);
    }
}
