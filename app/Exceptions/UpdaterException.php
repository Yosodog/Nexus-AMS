<?php

namespace App\Exceptions;

use RuntimeException;

class UpdaterException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'updater_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
