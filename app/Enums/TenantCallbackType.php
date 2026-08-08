<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantCallbackType: string
{
    case BootstrapRedeemed = 'bootstrap.redeemed';
}
