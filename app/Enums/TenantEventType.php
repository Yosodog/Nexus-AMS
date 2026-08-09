<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantEventType: string
{
    case WarDeclared = 'war.declared';
}
