<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantControlPurpose: string
{
    case TenantCallbackRequest = 'tenant.callback.request';
    case TenantCallbackResponse = 'tenant.callback.response';
}
