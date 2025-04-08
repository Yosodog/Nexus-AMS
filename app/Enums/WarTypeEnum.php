<?php

namespace App\Enums;

enum WarTypeEnum: string
{
    case ORDINARY = "ORDINARY";
    case ATTRITION = "ATTRITION";
    case RAID = "RAID";

    /**
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

}
