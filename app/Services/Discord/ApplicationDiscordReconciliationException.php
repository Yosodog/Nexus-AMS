<?php

namespace App\Services\Discord;

use RuntimeException;

final class ApplicationDiscordReconciliationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
