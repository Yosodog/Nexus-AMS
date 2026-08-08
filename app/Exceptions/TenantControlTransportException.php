<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantControlTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
        public readonly ?int $responseStatus = null,
    ) {
        parent::__construct('Tenant control request failed.');
    }
}
