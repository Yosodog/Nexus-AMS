<?php

namespace App\Enums;

enum OperationsPriority: string
{
    case P0 = 'p0';
    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';

    public function rank(): int
    {
        return match ($this) {
            self::P0 => 0,
            self::P1 => 1,
            self::P2 => 2,
            self::P3 => 3,
        };
    }
}
