<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Structured exception for application lifecycle failures.
 *
 * @property-read string $error
 * @property-read int $status
 * @property-read array<string, mixed> $context
 */
class ApplicationException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = []
    ) {
        parent::__construct($message, $status);
    }
}
