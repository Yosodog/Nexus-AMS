<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\Allocation\AllocationObjective;
use App\Domain\Milcom\Allocation\CandidateEdge;
use App\Domain\Milcom\Allocation\CandidatePool;
use App\Domain\Milcom\Allocation\ScarcityFirstAllocator;
use App\Domain\Milcom\Enums\PriorityTier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CandidatePoolTest extends TestCase
{
    #[Test]
    public function it_preserves_order_scores_and_lookup_precision(): void
    {
        $pool = CandidatePool::fromEdges(11, [
            new CandidateEdge(11, 101, 91.23, 99.91),
            new CandidateEdge(11, 102, 88.47, 95.55),
            new CandidateEdge(12, 999, 100, 100),
        ]);

        $this->assertCount(2, $pool);
        $this->assertSame([101, 102], array_map(
            static fn (CandidateEdge $edge): int => $edge->nationId,
            iterator_to_array($pool),
        ));
        $this->assertSame(91.23, $pool->findNation(101)?->score);
        $this->assertSame(95.55, $pool->findNation(102)?->confidence);
        $this->assertNull($pool->findNation(999));
    }

    #[Test]
    public function compact_pools_produce_the_same_allocation_as_edge_arrays(): void
    {
        $objectives = [
            new AllocationObjective(1, PriorityTier::Critical, 1, 2),
            new AllocationObjective(2, PriorityTier::Standard, 1, 1),
        ];
        $edges = [
            1 => [
                new CandidateEdge(1, 10, 95, 100),
                new CandidateEdge(1, 20, 80, 100),
            ],
            2 => [
                new CandidateEdge(2, 10, 90, 100),
                new CandidateEdge(2, 20, 70, 100),
            ],
        ];
        $pools = [
            1 => CandidatePool::fromEdges(1, $edges[1]),
            2 => CandidatePool::fromEdges(2, $edges[2]),
        ];
        $allocator = new ScarcityFirstAllocator;

        $arrayResult = $allocator->allocatePrepared($objectives, $edges, [10 => 1, 20 => 1]);
        $poolResult = $allocator->allocatePrepared($objectives, $pools, [10 => 1, 20 => 1]);

        $this->assertSame($arrayResult->assignments, $poolResult->assignments);
        $this->assertSame($arrayResult->unfilledMinimum, $poolResult->unfilledMinimum);
        $this->assertSame($arrayResult->unfilledDesired, $poolResult->unfilledDesired);
    }
}
