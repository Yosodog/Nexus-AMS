<?php

namespace App\Enums;

enum BackupRunTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Manual => 'Manual',
        };
    }
}
