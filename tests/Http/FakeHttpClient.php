<?php

declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Infrastructure\Http\HttpClient;
use Broadcast\Infrastructure\Http\HttpResponse;
use Broadcast\Infrastructure\Http\HttpTransportException;

final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: int}> */
    public array $requests = [];

    /** @var list<HttpResponse|HttpTransportException> */
    private array $outcomes = [];

    public function respond(HttpResponse $response): void
    {
        $this->outcomes[] = $response;
    }

    public function fail(HttpTransportException $exception): void
    {
        $this->outcomes[] = $exception;
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];

        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof HttpTransportException) {
            throw $outcome;
        }

        if (!$outcome instanceof HttpResponse) {
            throw new \LogicException('No fake HTTP outcome was configured.');
        }

        return $outcome;
    }
}
