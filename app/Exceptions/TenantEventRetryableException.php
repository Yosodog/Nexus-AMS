<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantEventRetryableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant event dependency is not ready.');
    }
}
