<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\Allocation\AllocationObjective;
use App\Domain\Milcom\Allocation\AllocationResult;
use App\Domain\Milcom\Allocation\CandidateEdge;
use App\Domain\Milcom\Allocation\ScarcityFirstAllocator;
use App\Domain\Milcom\Enums\PriorityTier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScarcityFirstAllocatorTest extends TestCase
{
    private ScarcityFirstAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = new ScarcityFirstAllocator;
    }

    #[Test]
    public function identical_snapshots_are_deterministic_across_input_orderings(): void
    {
        $objectives = [
            $this->objective(30, PriorityTier::Standard, 1, 1),
            $this->objective(10, PriorityTier::Critical, 1, 2),
            $this->objective(20, PriorityTier::High, 1, 2),
        ];
        $edges = [
            $this->edge(30, 4, 85.0),
            $this->edge(10, 2, 91.0),
            $this->edge(20, 3, 88.0),
            $this->edge(10, 1, 91.0),
            $this->edge(20, 2, 90.0),
            $this->edge(30, 1, 89.0),
            $this->edge(10, 3, 87.0),
            $this->edge(20, 4, 86.0),
        ];
        $capacities = [1 => 1, 2 => 1, 3 => 2, 4 => 1];

        $forward = $this->allocator->allocate($objectives, $edges, $capacities);
        $reversed = $this->allocator->allocate(
            array_reverse($objectives),
            array_reverse($edges),
            array_reverse($capacities, true),
        );

        $this->assertSame($forward->jsonSerialize(), $reversed->jsonSerialize());
    }

    #[Test]
    public function critical_minimum_coverage_precedes_a_higher_scoring_lower_tier_edge(): void
    {
        $result = $this->allocator->allocate(
            [
                $this->objective(10, PriorityTier::Critical, 1, 1),
                $this->objective(1, PriorityTier::Standard, 1, 1),
            ],
            [
                $this->edge(10, 100, 50.0),
                $this->edge(1, 100, 100.0),
            ],
            [100 => 1],
        );

        $this->assertSame([100], $this->nationIds($result, 10));
        $this->assertSame([], $this->nationIds($result, 1));
        $this->assertSame([1 => 1], $result->unfilledMinimum);
    }

    #[Test]
    public function scarcer_objective_is_staffed_first_within_the_same_tier(): void
    {
        $result = $this->allocator->allocate(
            [
                $this->objective(1, PriorityTier::Critical, 1, 1),
                $this->objective(2, PriorityTier::Critical, 1, 1),
            ],
            [
                $this->edge(1, 10, 100.0),
                $this->edge(1, 11, 80.0),
                $this->edge(2, 10, 70.0),
            ],
            [10 => 1, 11 => 1],
        );

        $this->assertSame([11], $this->nationIds($result, 1));
        $this->assertSame([10], $this->nationIds($result, 2));
        $this->assertSame([], $result->unfilledMinimum);
    }

    #[Test]
    public function locked_assignments_are_preserved_and_consume_capacity(): void
    {
        $result = $this->allocator->allocate(
            [
                $this->objective(1, PriorityTier::Critical, 1, 1, [10]),
                $this->objective(2, PriorityTier::Standard, 1, 1),
            ],
            [
                $this->edge(1, 10, 88.0, 92.0),
                $this->edge(2, 10, 100.0),
                $this->edge(2, 11, 70.0),
            ],
            [10 => 1, 11 => 1],
        );

        $this->assertSame([10], $this->nationIds($result, 1));
        $this->assertTrue($result->assignments[1][0]['locked']);
        $this->assertSame(88.0, $result->assignments[1][0]['score']);
        $this->assertSame(92.0, $result->assignments[1][0]['confidence']);
        $this->assertSame([11], $this->nationIds($result, 2));
    }

    #[Test]
    public function bounded_repair_moves_a_shared_candidate_to_an_unfilled_critical_objective(): void
    {
        $result = $this->allocator->allocate(
            [
                $this->objective(1, PriorityTier::Critical, 1, 1),
                $this->objective(2, PriorityTier::Critical, 1, 1),
            ],
            [
                $this->edge(1, 10, 100.0),
                $this->edge(1, 11, 90.0),
                $this->edge(2, 10, 95.0),
                $this->edge(2, 12, 20.0),
                $this->edge(2, 13, 10.0),
            ],
            [10 => 1, 11 => 1, 12 => 0, 13 => 0],
        );

        $this->assertSame([11], $this->nationIds($result, 1));
        $this->assertSame([10], $this->nationIds($result, 2));
        $this->assertSame([], $result->unfilledMinimum);
    }

    #[Test]
    public function edges_for_unknown_held_or_capacity_exhausted_pairs_are_never_assigned(): void
    {
        $result = $this->allocator->allocate(
            [
                $this->objective(1, PriorityTier::Standard, 1, 1),
                $this->objective(2, PriorityTier::Hold, 0, 0),
            ],
            [
                $this->edge(999, 10, 100.0),
                $this->edge(1, 11, 99.0),
                $this->edge(1, 12, 80.0),
                $this->edge(2, 13, 100.0),
            ],
            [10 => 1, 11 => 0, 12 => 1, 13 => 1],
        );

        $this->assertSame([12], $this->nationIds($result, 1));
        $this->assertSame([], $this->nationIds($result, 2));
        $this->assertArrayNotHasKey(999, $result->assignments);
    }

    #[Test]
    public function locked_assignments_cannot_start_above_nation_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Locked team spots exceed offensive capacity for nation 10.');

        $this->allocator->allocate(
            [
                $this->objective(1, PriorityTier::Critical, 1, 1, [10]),
                $this->objective(2, PriorityTier::High, 1, 1, [10]),
            ],
            [],
            [10 => 1],
        );
    }

    #[Test]
    #[DataProvider('propertyFixtureProvider')]
    public function deterministic_property_fixtures_never_overflow_capacity_or_emit_non_candidate_edges(
        int $salt,
        int $objectiveCount,
        int $nationCount,
    ): void {
        $objectives = [];
        $objectiveById = [];

        for ($objectiveId = 1; $objectiveId <= $objectiveCount; $objectiveId++) {
            $tier = match ($objectiveId % 4) {
                1 => PriorityTier::Critical,
                2 => PriorityTier::High,
                3 => PriorityTier::Standard,
                default => PriorityTier::Hold,
            };
            $depth = $tier->defaultDepth();
            $objective = $this->objective(
                $objectiveId,
                $tier,
                $depth['minimum'],
                $depth['desired'],
            );
            $objectives[] = $objective;
            $objectiveById[$objectiveId] = $objective;
        }

        $capacities = [];
        $edges = [];
        $candidatePairs = [];

        for ($nationId = 1; $nationId <= $nationCount; $nationId++) {
            $capacities[$nationId] = ($nationId + $salt) % 3;
        }

        foreach ($objectives as $objective) {
            for ($nationId = 1; $nationId <= $nationCount; $nationId++) {
                if ((($objective->id * 13) + ($nationId * 7) + $salt) % 5 === 0) {
                    continue;
                }

                $edge = $this->edge(
                    $objective->id,
                    $nationId,
                    40.0 + (($objective->id * 11 + $nationId * 17 + $salt) % 61),
                    60.0 + (($objective->id * 3 + $nationId * 5 + $salt) % 41),
                );
                $edges[] = $edge;
                $candidatePairs["{$objective->id}:{$nationId}"] = true;
            }
        }

        $forward = $this->allocator->allocate($objectives, $edges, $capacities);
        $reversed = $this->allocator->allocate(
            array_reverse($objectives),
            array_reverse($edges),
            array_reverse($capacities, true),
        );

        $this->assertSame($forward->jsonSerialize(), $reversed->jsonSerialize());

        $loads = [];

        foreach ($forward->assignments as $objectiveId => $assignments) {
            $this->assertLessThanOrEqual(
                $objectiveById[$objectiveId]->desiredDepth,
                count($assignments),
                "Objective {$objectiveId} exceeded desired depth.",
            );

            if ($objectiveById[$objectiveId]->tier === PriorityTier::Hold) {
                $this->assertSame([], $assignments);
            }

            foreach ($assignments as $assignment) {
                $nationId = $assignment['nation_id'];
                $loads[$nationId] = ($loads[$nationId] ?? 0) + 1;
                $this->assertArrayHasKey("{$objectiveId}:{$nationId}", $candidatePairs);
            }
        }

        foreach ($loads as $nationId => $load) {
            $this->assertLessThanOrEqual(
                $capacities[$nationId],
                $load,
                "Nation {$nationId} exceeded capacity in fixture {$salt}.",
            );
        }
    }

    public static function propertyFixtureProvider(): array
    {
        return [
            'small sparse' => [1, 7, 9],
            'small dense' => [2, 8, 12],
            'mixed tiers' => [7, 13, 16],
            'larger deterministic' => [19, 20, 24],
        ];
    }

    /** @param list<int> $lockedNationIds */
    private function objective(
        int $id,
        PriorityTier $tier,
        int $minimumDepth,
        int $desiredDepth,
        array $lockedNationIds = [],
    ): AllocationObjective {
        return new AllocationObjective(
            id: $id,
            tier: $tier,
            minimumDepth: $minimumDepth,
            desiredDepth: $desiredDepth,
            lockedNationIds: $lockedNationIds,
        );
    }

    private function edge(
        int $objectiveId,
        int $nationId,
        float $score,
        float $confidence = 100.0,
    ): CandidateEdge {
        return new CandidateEdge($objectiveId, $nationId, $score, $confidence);
    }

    /** @return list<int> */
    private function nationIds(AllocationResult $result, int $objectiveId): array
    {
        return array_column($result->assignments[$objectiveId], 'nation_id');
    }
}
