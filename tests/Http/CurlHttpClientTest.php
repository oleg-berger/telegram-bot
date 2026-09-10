<?php

declare(strict_types=1);

namespace Broadcast\Tests\Http;

use Broadcast\Infrastructure\Http\CurlHttpClient;
use Broadcast\Infrastructure\Http\HttpTransportException;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    public function testTransportFailureDoesNotEmitRuntimeDeprecations(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        }, E_DEPRECATED);

        try {
            $this->expectException(HttpTransportException::class);
            (new CurlHttpClient())->post('https://127.0.0.1:1', [], '', 1);
        } finally {
            restore_error_handler();
        }
    }
}
