<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Http;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse;
}
