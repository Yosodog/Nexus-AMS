<?php

namespace App\Enums;

enum AlertSeverity: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function priority(): int
    {
        return match ($this) {
            self::Low => 25,
            self::Normal => 50,
            self::High => 75,
            self::Critical => 100,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }
}
