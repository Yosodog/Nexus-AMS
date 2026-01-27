<?php

namespace App\Services\WarSimulator\Support;

final class WarSimRng
{
    private ?int $seed;

    private int $state;

    public function __construct(?int $seed = null)
    {
        $this->seed = $seed;
        if ($seed === null) {
            $this->state = 0;

            return;
        }

        $this->state = $seed === 0 ? 1 : $seed;
    }

    public function nextFloat(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }

        $ratio = $this->seed === null ? $this->secureRatio() : $this->seededRatio();

        return $min + (($max - $min) * $ratio);
    }

    private function seededRatio(): float
    {
        $x = $this->state;
        $x ^= ($x << 13);
        $x ^= ($x >> 17);
        $x ^= ($x << 5);
        $this->state = $x & 0xFFFFFFFF;

        return ($this->state & 0xFFFFFFFF) / 0xFFFFFFFF;
    }

    private function secureRatio(): float
    {
        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
    }
}
