<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantControlPurpose: string
{
    case BootstrapIntrospectionRequest = 'bootstrap.introspection.request';
    case BootstrapIntrospectionResponse = 'bootstrap.introspection.response';
    case TenantCallbackRequest = 'tenant.callback.request';
    case TenantCallbackResponse = 'tenant.callback.response';
}
