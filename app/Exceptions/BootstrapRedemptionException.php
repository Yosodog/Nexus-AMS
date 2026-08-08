<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class BootstrapRedemptionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct('Bootstrap could not be completed.');
    }
}
