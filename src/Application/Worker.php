<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\TextParts;

final class Worker
{
    public function __construct(
        private Store $store,
        private TelegramGateway $telegram,
        private Translator $translator,
        private ?\Closure $logger = null,
    ) {}

    /** Perform at most one external operation. Runtime throttles ticks; tests can control time. */
    public function tick(?int $now = null): bool
    {
        $now ??= time();
        $this->store->refreshBroadcasts();
        $job = $this->store->nextJob($now);
        if ($job === null) { return false; }
        try {
            match ($job['kind']) {
                'translate' => $this->translate($job),
                'delivery' => $this->deliver($job),
                'message' => $this->message($job),
                'callback' => $this->callback($job),
            };
            $this->log('job_processed', $job);
        } catch (ApiFailure $failure) {
            $attempts = $job['attempts'] + 1;
            $failed = !$failure->transient || $attempts >= 5;
            $error = $failure->blocked ? 'blocked' : ($failure->transient ? 'temporary_api_error' : 'permanent_api_error');
            // Error text is deliberately not persisted: upstream responses can echo personal data.
            $this->store->deferJob($job['id'], $attempts, $now + max($failure->retryAfter ?? 0, 2 ** $attempts), $error, $failed);
            $this->log($failed ? 'job_failed' : 'job_deferred', $job, $attempts);
            if ($failure->blocked && $job['user_id'] !== null) { $this->store->disableSubscriber($job['user_id']); }
            if ($failed && $job['kind'] === 'translate') {
                $broadcast = $this->store->broadcast($job['broadcast_id']);
                $this->store->queueReply($broadcast['admin_id'], sprintf('Рассылка #%d: не удалось подготовить перевод %s. Проверьте доступ и квоту DeepL. /retry %d', $broadcast['id'], $job['language'], $broadcast['id']));
            }
        }
        $this->store->refreshBroadcasts();
        return true;
    }

    private function log(string $event, array $job, ?int $attempt = null): void
    {
        if ($this->logger === null) { return; }
        $record = ['event' => $event, 'job_id' => $job['id'], 'kind' => $job['kind']];
        if ($attempt !== null) { $record['attempt'] = $attempt; }
        ($this->logger)($record);
    }

    private function translate(array $job): void
    {
        if ($this->store->translation($job['broadcast_id'], $job['language']) === null) {
            $broadcast = $this->store->broadcast($job['broadcast_id']);
            $translation = $this->translator->translate($broadcast['text'], $job['language']);
            if (trim($translation) === '') { throw new ApiFailure('Empty translation.'); }
            $this->store->saveTranslation($job['broadcast_id'], $job['language'], $translation);
        }
        $this->store->completeJob($job['id']);
    }

    private function deliver(array $job): void
    {
        $user = $this->store->user($job['user_id']);
        if ($user === null || !$user['subscribed']) { $this->store->completeJob($job['id'], 'skipped'); return; }
        $parts = TextParts::split($this->store->translation($job['broadcast_id'], $job['language']));
        if (!isset($parts[$job['part']])) { $this->store->completeJob($job['id']); return; }
        $this->telegram->sendMessage($job['user_id'], $parts[$job['part']]);
        // Record each successful chunk so a retry continues from the first unsent chunk.
        $this->store->advancePart($job['id']);
        if ($job['part'] + 1 === count($parts)) { $this->store->completeJob($job['id']); }
    }

    private function message(array $job): void
    {
        $this->telegram->sendMessage($job['user_id'], $job['payload']['text'], $job['payload']['options']);
        $this->store->completeJob($job['id']);
    }

    private function callback(array $job): void
    {
        $this->telegram->answerCallbackQuery($job['payload']['id']);
        $this->store->completeJob($job['id']);
    }
}
