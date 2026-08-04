<?php

namespace App\Domain\Milcom\Allocation;

use App\Domain\Milcom\Enums\PriorityTier;

final readonly class AllocationObjective
{
    /**
     * @param  list<int>  $lockedNationIds
     */
    public function __construct(
        public int $id,
        public PriorityTier $tier,
        public int $minimumDepth,
        public int $desiredDepth,
        public array $lockedNationIds = [],
    ) {}
}
