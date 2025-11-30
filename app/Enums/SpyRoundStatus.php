<?php

namespace App\Enums;

enum SpyRoundStatus: string
{
    case DRAFT = 'draft';
    case ASSIGNED = 'assigned';
    case COMPLETED = 'completed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
