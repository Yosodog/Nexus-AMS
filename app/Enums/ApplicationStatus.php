<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Denied = 'DENIED';
    case Cancelled = 'CANCELLED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
