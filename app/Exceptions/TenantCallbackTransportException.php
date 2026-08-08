<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantCallbackTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
        public readonly ?int $responseStatus = null,
    ) {
        parent::__construct('Tenant callback delivery failed.');
    }
}
