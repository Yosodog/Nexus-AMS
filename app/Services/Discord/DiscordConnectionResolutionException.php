<?php

namespace App\Services\Discord;

use RuntimeException;

class DiscordConnectionResolutionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }
}
