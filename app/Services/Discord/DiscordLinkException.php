<?php

namespace App\Services\Discord;

use RuntimeException;

final class DiscordLinkException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message, $status);
    }
}
