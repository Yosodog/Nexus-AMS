<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantControlConfigurationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant control authentication is unavailable.');
    }
}
