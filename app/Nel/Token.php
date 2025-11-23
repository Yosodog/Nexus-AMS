<?php

namespace App\Nel;

final class Token
{
    public function __construct(
        public readonly string $type,
        public readonly mixed $value,
        public readonly int $position
    ) {}
}
