<?php

declare(strict_types=1);

namespace Broadcast\Application;

interface TelegramGateway
{
    /** @param array<string, mixed> $options */
    public function sendMessage(int $chatId, string $text, array $options = []): void;

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 25): array;

    public function answerCallbackQuery(string $id): void;
}
