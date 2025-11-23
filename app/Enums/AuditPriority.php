<?php

namespace App\Enums;

enum AuditPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
