<?php

namespace App\Services;

class GraphQLLiteral
{
    public function __construct(protected string $value) {}

    public static function make(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function value(): string
    {
        return $this->value;
    }
}
