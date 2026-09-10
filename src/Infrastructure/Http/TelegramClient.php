<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use Broadcast\Application\TelegramGateway;
use Broadcast\Domain\ApiFailure;
use JsonException;

final class TelegramClient implements TelegramGateway
{
    private const string API_BASE = 'https://api.telegram.org';

    private readonly HttpClient $http;

    public function __construct(private readonly string $token, ?HttpClient $http = null)
    {
        $this->http = $http ?? new CurlHttpClient();
    }

    public function sendMessage(int $chatId, string $text, array $options = []): void
    {
        unset($options['parse_mode']);
        $this->call('sendMessage', [
            ...$options,
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function getUpdates(int $offset, int $timeout = 25): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], max(1, $timeout + 5));

        if (!is_array($result) || !array_is_list($result)) {
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }

        return $result;
    }

    public function answerCallbackQuery(string $id): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id]);
    }

    /** @param array<string, mixed> $payload */
    private function call(string $method, array $payload, int $timeoutSeconds = 30): mixed
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiFailure('Telegram request could not be encoded.');
        }

        try {
            $response = $this->http->post(
                self::API_BASE . '/bot' . $this->token . '/' . $method,
                ['Content-Type' => 'application/json'],
                $body,
                $timeoutSeconds,
            );
        } catch (HttpTransportException $exception) {
            throw new ApiFailure(
                $exception->timeout ? 'Telegram request timed out.' : 'Telegram request failed.',
                true,
            );
        }

        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            if ($response->status >= 400) {
                throw $this->apiFailure($response->status);
            }
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }

        if (!is_array($decoded)) {
            if ($response->status >= 400) {
                throw $this->apiFailure($response->status);
            }
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }

        if ($response->status >= 400 || ($decoded['ok'] ?? false) !== true) {
            $errorCode = is_int($decoded['error_code'] ?? null)
                ? $decoded['error_code']
                : $response->status;
            $retryAfter = $decoded['parameters']['retry_after'] ?? null;
            $retryAfter = is_int($retryAfter) && $retryAfter >= 0 ? $retryAfter : null;

            throw $this->apiFailure($errorCode, $retryAfter);
        }

        if (!array_key_exists('result', $decoded)) {
            throw new ApiFailure('Telegram returned an invalid response.', true);
        }

        return $decoded['result'];
    }

    private function apiFailure(int $errorCode, ?int $retryAfter = null): ApiFailure
    {
        return new ApiFailure(
            'Telegram API request failed.',
            $errorCode === 429 || $errorCode >= 500,
            $retryAfter,
            $errorCode === 403,
        );
    }
}
