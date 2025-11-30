<?php

namespace App\Enums;

enum SpyOperationType: string
{
    case GATHER_INTELLIGENCE = 'GATHER_INTELLIGENCE';
    case TERRORIZE_CIVILIANS = 'TERRORIZE_CIVILIANS';
    case SABOTAGE_SOLDIERS = 'SABOTAGE_SOLDIERS';
    case SABOTAGE_TANKS = 'SABOTAGE_TANKS';
    case SABOTAGE_AIRCRAFT = 'SABOTAGE_AIRCRAFT';
    case SABOTAGE_SHIPS = 'SABOTAGE_SHIPS';
    case ASSASSINATE_SPIES = 'ASSASSINATE_SPIES';
    case SABOTAGE_MISSILES = 'SABOTAGE_MISSILES';
    case SABOTAGE_NUKES = 'SABOTAGE_NUKES';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
