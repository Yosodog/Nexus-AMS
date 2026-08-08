<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantControlAuthenticationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant control response authentication failed.');
    }
}
