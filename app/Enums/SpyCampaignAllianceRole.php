<?php

namespace App\Enums;

enum SpyCampaignAllianceRole: string
{
    case ALLY = 'ally';
    case ENEMY = 'enemy';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
