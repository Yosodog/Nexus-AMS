<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantBootstrapAction: string
{
    case InitialAdmin = 'initial-admin';
}
