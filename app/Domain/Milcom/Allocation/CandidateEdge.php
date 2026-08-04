<?php

namespace App\Domain\Milcom\Allocation;

final readonly class CandidateEdge
{
    public function __construct(
        public int $objectiveId,
        public int $nationId,
        public float $score,
        public float $confidence,
    ) {}
}
