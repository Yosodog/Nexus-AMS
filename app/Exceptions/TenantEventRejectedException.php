<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\TenantEventRejectionReason;
use RuntimeException;

final class TenantEventRejectedException extends RuntimeException
{
    public function __construct(public readonly TenantEventRejectionReason $reason)
    {
        parent::__construct('Tenant event was rejected.');
    }
}
