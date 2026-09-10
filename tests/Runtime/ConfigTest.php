<?php

declare(strict_types=1);

namespace Broadcast\Tests\Runtime;

use Broadcast\Infrastructure\Runtime\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParsesRequiredEnvironmentWithoutChangingSecretValues(): void
    {
        $config = Config::fromArray([
            'TELEGRAM_BOT_TOKEN' => '123456:secret-token',
            'TELEGRAM_ADMIN_IDS' => '42,900719',
            'DEEPL_API_KEY' => 'deepl-secret',
            'DEEPL_API_URL' => 'https://api-free.deepl.com',
            'DATABASE_PATH' => '/data/app.sqlite',
        ]);

        self::assertSame('123456:secret-token', $config->telegramToken);
        self::assertSame([42, 900719], $config->adminIds);
        self::assertSame('deepl-secret', $config->deeplApiKey);
        self::assertSame('https://api-free.deepl.com', $config->deeplApiUrl);
        self::assertSame('/data/app.sqlite', $config->databasePath);
        self::assertSame('/data' . DIRECTORY_SEPARATOR . 'backups', $config->backupDirectory);
        self::assertSame('/data' . DIRECTORY_SEPARATOR . 'runtime', $config->runtimeDirectory);
    }

    #[DataProvider('invalidEnvironment')]
    public function testRejectsInvalidEnvironmentWithASecretFreeMessage(array $environment): void
    {
        try {
            Config::fromArray($environment);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid runtime configuration.', $exception->getMessage());
            self::assertStringNotContainsString('leaked-secret', $exception->getMessage());
        }
    }

    public static function invalidEnvironment(): iterable
    {
        $valid = [
            'TELEGRAM_BOT_TOKEN' => 'leaked-secret',
            'TELEGRAM_ADMIN_IDS' => '1',
            'DEEPL_API_KEY' => 'leaked-secret',
            'DEEPL_API_URL' => 'https://api.deepl.com',
            'DATABASE_PATH' => '/data/app.sqlite',
        ];

        foreach (array_keys($valid) as $key) {
            yield "missing {$key}" => [array_diff_key($valid, [$key => true])];
        }
        foreach (['', '0', '-1', '1, 2', '1,', '1,two', '9223372036854775808'] as $ids) {
            yield "admin ids {$ids}" => [[...$valid, 'TELEGRAM_ADMIN_IDS' => $ids]];
        }
    }
}
