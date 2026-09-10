<?php

declare(strict_types=1);

namespace Broadcast\Infrastructure\Runtime;

use InvalidArgumentException;

final readonly class Config
{
    private const string ERROR = 'Invalid runtime configuration.';

    private function __construct(
        public string $telegramToken,
        public array $adminIds,
        public string $deeplApiKey,
        public string $deeplApiUrl,
        public string $databasePath,
        public string $backupDirectory,
        public string $runtimeDirectory,
    ) {}

    /** @param array<string, mixed> $environment */
    public static function fromArray(array $environment): self
    {
        $token = self::required($environment, 'TELEGRAM_BOT_TOKEN');
        $adminList = self::required($environment, 'TELEGRAM_ADMIN_IDS');
        $apiKey = self::required($environment, 'DEEPL_API_KEY');
        $apiUrl = self::required($environment, 'DEEPL_API_URL');
        $databasePath = self::required($environment, 'DATABASE_PATH');

        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $adminList)) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $adminIds = [];
        foreach (explode(',', $adminList) as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed === false) {
                throw new InvalidArgumentException(self::ERROR);
            }
            $adminIds[] = $parsed;
        }

        $dataDirectory = dirname($databasePath);

        return new self(
            $token,
            array_values(array_unique($adminIds)),
            $apiKey,
            $apiUrl,
            $databasePath,
            $dataDirectory . DIRECTORY_SEPARATOR . 'backups',
            $dataDirectory . DIRECTORY_SEPARATOR . 'runtime',
        );
    }

    public static function fromEnvironment(): self
    {
        $environment = [];
        foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS', 'DEEPL_API_KEY', 'DEEPL_API_URL', 'DATABASE_PATH'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }

        return self::fromArray($environment);
    }

    /** @param array<string, mixed> $environment */
    private static function required(array $environment, string $key): string
    {
        $value = $environment[$key] ?? null;
        if (!is_string($value) || $value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException(self::ERROR);
        }

        return $value;
    }
}
