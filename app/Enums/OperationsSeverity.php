<?php

namespace App\Enums;

enum OperationsSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Moderate = 'moderate';
    case Low = 'low';
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Moderate => 2,
            self::Low => 1,
            self::Unknown => 0,
        };
    }
}
