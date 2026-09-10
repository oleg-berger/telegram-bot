<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Persistence boundary for registration and the durable outbox. All times are Unix seconds. */
interface Store
{
    public function transaction(callable $operation): mixed;
    public function acceptUpdate(int $id): bool;
    public function user(int $id): ?array;
    public function saveUser(array $user): void;
    public function queueReply(int $chatId, string $text, array $options = []): void;
    public function queueCallback(string $id): void;
    public function createBroadcast(int $adminId, string $text): array;
    public function broadcast(int $id): ?array;
    public function addCatchup(int $userId, string $language): void;
    public function retryBroadcast(int $id): bool;
    public function nextJob(int $now): ?array;
    public function completeJob(int $id, string $status = 'done'): void;
    public function advancePart(int $id): void;
    public function deferJob(int $id, int $attempts, int $nextAt, string $error, bool $failed): void;
    public function translation(int $broadcastId, string $language): ?string;
    public function saveTranslation(int $broadcastId, string $language, string $text): void;
    public function disableSubscriber(int $id): void;
    public function refreshBroadcasts(): void;
}
