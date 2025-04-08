<?php

namespace App\Enums;

enum AlliancePositionEnum: string
{
    case NOALLIANCE = "NOALLIANCE";
    case APPLICANT = "APPLICANT";
    case MEMBER = "MEMBER";
    case OFFICER = "OFFICER";
    case HEIR = "HEIR";
    case LEADER = "LEADER";

    /**
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
