<?php

declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;
use Broadcast\Infrastructure\Http\OpenAiTranslator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiTranslatorTest extends TestCase
{
    public function testTranslateUsesAuthenticatedJsonApiAndReturnsTranslatedText(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"Hallo"}}]}'));
        $translator = new OpenAiTranslator('translator-secret', 'https://api.moonshot.ai/v1', 'kimi-k2-0905-preview', 0, $http);

        $translated = $translator->translate('Hello', 'EN-GB');

        self::assertSame('Hallo', $translated);
        self::assertSame('https://api.moonshot.ai/v1/chat/completions', $http->requests[0]['url']);
        self::assertSame(
            ['Content-Type' => 'application/json', 'Authorization' => 'Bearer translator-secret'],
            $http->requests[0]['headers'],
        );
        self::assertSame(60, $http->requests[0]['timeout']);
        self::assertSame(
            [
                'model' => 'kimi-k2-0905-preview',
                'messages' => [
                    ['role' => 'system', 'content' => "Translate the user's text to British English. Preserve paragraphs. Return only the translation, without explanations or quotes."],
                    ['role' => 'user', 'content' => 'Hello'],
                ],
                'temperature' => 0,
                'stream' => false,
            ],
            json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testOmittedTemperatureIsNotSent(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"choices":[{"finish_reason":"stop","message":{"content":"ok"}}]}'));
        $translator = new OpenAiTranslator('key', 'https://api.moonshot.ai/v1', 'kimi-k2.6', null, $http);

        $translator->translate('text', 'RU');

        $body = json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('temperature', $body);
    }

    public function testLanguageCodesMapToPromptLanguages(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"choices":[{"finish_reason":"stop","message":{"content":"ok"}}]}'));
        $translator = new OpenAiTranslator('key', 'https://api.deepseek.com', 'deepseek-chat', null, $http);

        $translator->translate('text', 'ES');

        $body = json_decode($http->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Spanish', $body['messages'][0]['content']);
    }

    public function testUnknownLanguageIsNotSentToTheApi(): void
    {
        $translator = new OpenAiTranslator('key', 'https://api.deepseek.com', 'deepseek-chat', null, new FakeHttpClient());

        $this->expectExceptionObject(new ApiFailure('Unsupported target language.'));
        $translator->translate('text', 'PT-BR');
    }

    public static function incompleteResponses(): iterable
    {
        foreach (['length', 'content_filter', 'tool_calls', 'function_call', 'private-unknown-reason', '', null, 1, ['stop']] as $reason) {
            yield json_encode($reason) => [['finish_reason' => $reason]];
        }
        yield 'missing finish reason' => [[]];
    }

    #[DataProvider('incompleteResponses')]
    public function testIncompleteResponseIsRejectedWithoutLeakingContent(array $choice): void
    {
        $choice['message'] = ['content' => 'private truncated translation'];
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, json_encode(['choices' => [$choice]], JSON_THROW_ON_ERROR)));
        $translator = new OpenAiTranslator('translator-secret', 'https://example.com/v1', 'model', http: $http);

        try {
            $translator->translate('private original', 'RU');
            self::fail('An unfinished response must not be accepted.');
        } catch (ApiFailure $failure) {
            self::assertTrue($failure->transient);
            self::assertFalse($failure->blocked);
            self::assertNull($failure->retryAfter);
            self::assertSame('Translator did not complete the translation.', $failure->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'http' => ['http://api.deepseek.com'];
        yield 'embedded credentials' => ['https://user:password@api.deepseek.com'];
        yield 'query string' => ['https://api.deepseek.com?redirect=evil'];
        yield 'missing host' => ['https:///v1'];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function testConstructorRejectsUnsafeBaseUrl(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OpenAiTranslator('key', $baseUrl, 'model', null, new FakeHttpClient());
    }

    /** @return iterable<string, array{int, bool, ?int}> */
    public static function apiFailures(): iterable
    {
        yield 'forbidden' => [403, false, null];
        yield 'rate limited' => [429, true, 9];
        yield 'server failure' => [500, true, null];
        yield 'bad request' => [400, false, null];
    }

    #[DataProvider('apiFailures')]
    public function testApiFailuresAreClassifiedWithoutLeakingSecretsOrText(
        int $status,
        bool $transient,
        ?int $retryAfter,
    ): void {
        $http = new FakeHttpClient();
        $headers = $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter];
        $http->respond(new HttpResponse($status, '{"message":"private-text and translator-secret"}', $headers));
        $translator = new OpenAiTranslator('translator-secret', 'https://api.deepseek.com', 'deepseek-chat', null, $http);

        try {
            $translator->translate('private-text', 'RU');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertSame($transient, $failure->transient);
            self::assertSame($retryAfter, $failure->retryAfter);
            self::assertFalse($failure->blocked);
            self::assertStringNotContainsString('private-text', $failure->getMessage());
            self::assertStringNotContainsString('translator-secret', $failure->getMessage());
        }
    }

    public function testTransportTimeoutIsReportedAsSafeTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->fail(new HttpTransportException('private-text', true));
        $translator = new OpenAiTranslator('translator-secret', 'https://api.deepseek.com', 'deepseek-chat', null, $http);

        try {
            $translator->translate('private-text', 'RU');
            self::fail('Expected an API failure.');
        } catch (ApiFailure $failure) {
            self::assertTrue($failure->transient);
            self::assertStringNotContainsString('private-text', $failure->getMessage());
            self::assertStringNotContainsString('translator-secret', $failure->getMessage());
        }
    }

    public function testMalformedSuccessResponseIsTransientFailure(): void
    {
        $http = new FakeHttpClient();
        $http->respond(new HttpResponse(200, '{"choices":[]}'));
        $translator = new OpenAiTranslator('key', 'https://api.deepseek.com', 'deepseek-chat', null, $http);

        $this->expectExceptionObject(new ApiFailure('Translator returned an invalid response.', true));
        $translator->translate('Hello', 'FR');
    }
}
