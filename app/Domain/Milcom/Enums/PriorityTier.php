<?php

namespace App\Domain\Milcom\Enums;

enum PriorityTier: string
{
    case Critical = 'critical';
    case High = 'high';
    case Standard = 'standard';
    case Hold = 'hold';

    public function order(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Standard => 2,
            self::Hold => 3,
        };
    }

    /** @return array{desired: int, minimum: int} */
    public function defaultDepth(): array
    {
        return match ($this) {
            self::Critical => ['desired' => 3, 'minimum' => 2],
            self::High => ['desired' => 2, 'minimum' => 1],
            self::Standard => ['desired' => 1, 'minimum' => 1],
            self::Hold => ['desired' => 0, 'minimum' => 0],
        };
    }
}
