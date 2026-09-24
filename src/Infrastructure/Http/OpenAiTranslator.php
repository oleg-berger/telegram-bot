<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

use Broadcast\Application\Translator;
use Broadcast\Domain\ApiFailure;
use InvalidArgumentException;
use JsonException;

/** Translation through any OpenAI-compatible chat completions API (DeepSeek, Moonshot Kimi, OpenRouter, ...). */
final class OpenAiTranslator implements Translator
{
    private const array LANGUAGES = ['RU' => 'Russian', 'EN-GB' => 'British English', 'ES' => 'Spanish', 'FR' => 'French'];

    private readonly HttpClient $http;
    private readonly string $baseUrl;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        private readonly string $model,
        private readonly ?float $temperature = null,
        ?HttpClient $http = null,
    ) {
        $this->baseUrl = $this->validateBaseUrl($baseUrl);
        $this->http = $http ?? new CurlHttpClient();
    }

    public function translate(string $text, string $targetLanguage): string
    {
        $language = self::LANGUAGES[$targetLanguage] ?? null;
        if ($language === null) {
            throw new ApiFailure('Unsupported target language.');
        }

        try {
            $body = json_encode([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => "Translate the user's text to $language. Preserve paragraphs. Return only the translation, without explanations or quotes."],
                    ['role' => 'user', 'content' => $text],
                ],
                // Some providers allow only their default temperature; it is sent only when configured.
                ...($this->temperature === null ? [] : ['temperature' => $this->temperature]),
                'stream' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiFailure('Translator request could not be encoded.');
        }

        try {
            $response = $this->http->post(
                $this->baseUrl . '/chat/completions',
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                $body,
                60,
            );
        } catch (HttpTransportException $exception) {
            throw new ApiFailure(
                $exception->timeout ? 'Translator request timed out.' : 'Translator request failed.',
                true,
            );
        }

        if ($response->status >= 400) {
            throw new ApiFailure(
                'Translator API request failed.',
                $response->status === 429 || $response->status >= 500,
                $this->retryAfter($response),
            );
        }

        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiFailure('Translator returned an invalid response.', true);
        }

        $translation = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
        if (!is_string($translation)) {
            throw new ApiFailure('Translator returned an invalid response.', true);
        }
        // Non-empty content can still be truncated or interrupted by the provider.
        if (($decoded['choices'][0]['finish_reason'] ?? null) !== 'stop') {
            throw new ApiFailure('Translator did not complete the translation.', true);
        }

        return $translation;
    }

    /** The API key is sent to this URL, so it must at least be HTTPS without embedded credentials. */
    private function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : null;

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || $host === null
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Translator base URL is not allowed.');
        }

        $base = 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $base . rtrim($parts['path'] ?? '', '/');
    }

    private function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
