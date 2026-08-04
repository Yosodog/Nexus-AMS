<?php

namespace App\Domain\Milcom\Allocation;

use JsonSerializable;

final readonly class AllocationResult implements JsonSerializable
{
    /**
     * @param  array<int, list<array{nation_id: int, score: float, confidence: float, locked: bool}>>  $assignments
     * @param  array<int, int>  $unfilledMinimum
     * @param  array<int, int>  $unfilledDesired
     */
    public function __construct(
        public array $assignments,
        public array $unfilledMinimum,
        public array $unfilledDesired,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'assignments' => $this->assignments,
            'unfilled_minimum' => $this->unfilledMinimum,
            'unfilled_desired' => $this->unfilledDesired,
        ];
    }
}
