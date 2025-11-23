<?php

namespace App\Enums;

enum AuditTargetType: string
{
    case Nation = 'nation';
    case City = 'city';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
