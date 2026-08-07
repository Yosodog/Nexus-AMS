<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class PWQueryFailedException extends Exception
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
