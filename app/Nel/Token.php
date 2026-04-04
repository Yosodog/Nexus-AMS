<?php

namespace App\Nel;

final readonly class Token
{
    public function __construct(
        public string $type,
        public mixed $value,
        public int $position
    ) {}
}
