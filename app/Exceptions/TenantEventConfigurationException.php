<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TenantEventConfigurationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant event transport is not safely configured.');
    }
}
