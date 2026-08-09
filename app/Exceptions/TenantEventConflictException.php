<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantEventConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant event identity conflicts with an existing receipt.');
    }
}
