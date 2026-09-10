<?php

declare(strict_types=1);

namespace Broadcast\Domain;

use RuntimeException;

final class ApiFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient = false,
        public readonly ?int $retryAfter = null,
        public readonly bool $blocked = false,
    ) {
        parent::__construct($message);
    }
}
