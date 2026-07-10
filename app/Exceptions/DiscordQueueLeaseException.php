<?php

namespace App\Exceptions;

use RuntimeException;

class DiscordQueueLeaseException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message, $status);
    }
}
