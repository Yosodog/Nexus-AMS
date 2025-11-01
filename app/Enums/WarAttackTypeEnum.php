<?php

namespace App\Enums;

enum WarAttackTypeEnum: string
{
    case AIRVINFRA = 'AIRVINFRA';
    case AIRVSOLDIERS = 'AIRVSOLDIERS';
    case AIRVTANKS = 'AIRVTANKS';
    case AIRVMONEY = 'AIRVMONEY';
    case AIRVSHIPS = 'AIRVSHIPS';
    case AIRVAIR = 'AIRVAIR';
    case GROUND = 'GROUND';
    case MISSILE = 'MISSILE';
    case MISSILEFAIL = 'MISSILEFAIL';
    case NUKE = 'NUKE';
    case NUKEFAIL = 'NUKEFAIL';
    case NAVAL = 'NAVAL';
    case NAVALVSHIPS = 'NAVALVSHIPS';
    case NAVALVINFRA = 'NAVALVINFRA';
    case NAVALVGROUND = 'NAVALVGROUND';
    case NAVALVAIR = 'NAVALVAIR';
    case FORTIFY = 'FORTIFY';
    case PEACE = 'PEACE';
    case VICTORY = 'VICTORY';
    case ALLIANCELOOT = 'ALLIANCELOOT';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
