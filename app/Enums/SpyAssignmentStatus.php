<?php

namespace App\Enums;

enum SpyAssignmentStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
