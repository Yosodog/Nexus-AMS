<?php

namespace App\Services\Discord\Relay;

use RuntimeException;

class DiscordRelayProofException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 401,
    ) {
        parent::__construct($message);
    }
}
