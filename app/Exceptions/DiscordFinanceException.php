<?php

namespace App\Exceptions;

use RuntimeException;

class DiscordFinanceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
