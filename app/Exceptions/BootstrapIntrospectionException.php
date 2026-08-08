<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class BootstrapIntrospectionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly int $httpStatus,
    ) {
        parent::__construct(
            $retryable
                ? 'Bootstrap verification is temporarily unavailable.'
                : 'Bootstrap could not be authorized.',
        );
    }
}
