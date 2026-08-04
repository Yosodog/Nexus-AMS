<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\Allocation\AllocationObjective;
use App\Domain\Milcom\Allocation\CandidateEdge;
use App\Domain\Milcom\Allocation\CandidatePool;
use App\Domain\Milcom\Allocation\ScarcityFirstAllocator;
use App\Domain\Milcom\CounterTeamSelector;
use App\Domain\Milcom\EligibilityEvaluator;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\FixedDoctrineScorer;
use App\Domain\Milcom\MilcomGameRules;
use App\Domain\Milcom\PairAssessment;
use App\Domain\Milcom\ReadinessProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MilcomPerformanceBudgetTest extends TestCase
{
    #[Test]
    public function two_thousand_target_allocator_fixture_finishes_inside_the_generation_budget(): void
    {
        $objectives = [];
        $edgesByObjective = [];
        $capacities = array_fill_keys(range(1, 500), 5);
        $now = new DateTimeImmutable;
        $eligibility = new EligibilityEvaluator(new MilcomGameRules);
        $scorer = new FixedDoctrineScorer;
        $friendlies = [];

        for ($nationId = 1; $nationId <= 500; $nationId++) {
            $friendlies[$nationId] = $this->readinessProfile(
                nationId: $nationId,
                allianceId: 1,
                score: 2_000 + ($nationId % 500),
                now: $now,
            );
        }

        $started = hrtime(true);
        $memoryBefore = memory_get_usage(true);

        for ($objectiveId = 1; $objectiveId <= 2_000; $objectiveId++) {
            $tier = $objectiveId % 50 === 0
                ? PriorityTier::Critical
                : ($objectiveId % 10 === 0 ? PriorityTier::High : PriorityTier::Standard);
            $depth = $tier->defaultDepth();
            $objectives[] = new AllocationObjective(
                id: $objectiveId,
                tier: $tier,
                minimumDepth: $depth['minimum'],
                desiredDepth: $depth['desired'],
            );
            $target = $this->readinessProfile(
                nationId: 10_000 + $objectiveId,
                allianceId: 2,
                score: 2_100 + ($objectiveId % 600),
                now: $now,
            );
            $assessments = [];

            foreach ($friendlies as $nationId => $friendly) {
                $result = $eligibility->evaluate(
                    $friendly,
                    $target,
                    [1],
                    OperationType::Plan,
                    at: $now,
                );

                if (! $result->eligible()) {
                    continue;
                }

                $assessment = $scorer->assess($friendly, $target, $now);
                $assessments[] = new CandidateEdge(
                    objectiveId: $objectiveId,
                    nationId: $nationId,
                    score: $assessment->score,
                    confidence: $assessment->confidence,
                );
            }

            usort($assessments, static fn (CandidateEdge $left, CandidateEdge $right): int => [
                -$left->score,
                $left->nationId,
            ] <=> [
                -$right->score,
                $right->nationId,
            ]);
            $edgesByObjective[$objectiveId] = CandidatePool::fromEdges(
                $objectiveId,
                array_slice($assessments, 0, 40),
            );
        }

        $result = (new ScarcityFirstAllocator)->allocatePrepared($objectives, $edgesByObjective, $capacities);
        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $memoryGrowth = memory_get_peak_usage(true) - $memoryBefore;

        $this->assertLessThan(60, $elapsedSeconds);
        $this->assertLessThan(48 * 1024 * 1024, $memoryGrowth);
        $this->assertCount(2_000, $result->assignments);
        $criticalIds = array_values(array_filter(
            range(1, 2_000),
            static fn (int $objectiveId): bool => $objectiveId % 50 === 0,
        ));
        $this->assertSame([], array_intersect_key(
            $result->unfilledMinimum,
            array_fill_keys($criticalIds, true),
        ));
        $loadByNation = [];

        foreach ($result->assignments as $assignments) {
            foreach ($assignments as $assignment) {
                $loadByNation[$assignment['nation_id']] = ($loadByNation[$assignment['nation_id']] ?? 0) + 1;
            }
        }

        $this->assertLessThanOrEqual(5, max($loadByNation));
    }

    #[Test]
    public function top_twenty_counter_combination_fixture_finishes_inside_the_latency_budget(): void
    {
        $assessments = [];

        for ($nationId = 1; $nationId <= 20; $nationId++) {
            $assessments[] = new PairAssessment(
                friendlyNationId: $nationId,
                targetNationId: 9_999,
                score: 60.0 + $nationId,
                confidence: 90.0,
                factors: [
                    'air' => 50.0 + $nationId,
                    'ground' => 55.0 + $nationId,
                    'naval' => 45.0 + $nationId,
                    'readiness' => 80.0,
                    'tactical_fit' => 75.0,
                    'activity' => 100.0,
                ],
                warnings: [],
                explanation: [],
            );
        }

        $started = hrtime(true);
        $selection = (new CounterTeamSelector)->select($assessments);
        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertLessThan(5, $elapsedSeconds);
        $this->assertCount(3, $selection['recommended']['nation_ids']);
        $this->assertCount(3, $selection['alternatives']);
    }

    private function readinessProfile(
        int $nationId,
        int $allianceId,
        float $score,
        DateTimeImmutable $now,
    ): ReadinessProfile {
        return new ReadinessProfile(
            nationId: $nationId,
            allianceId: $allianceId,
            alliancePosition: 'MEMBER',
            score: $score,
            cities: 20,
            vacationTurns: 0,
            beigeTurns: 0,
            activeOffensiveWars: 0,
            reservedOffensiveSlots: 0,
            soldiers: 200_000,
            tanks: 15_000,
            aircraft: 1_500,
            ships: 150,
            missiles: 0,
            nukes: 0,
            lastActiveAt: $now,
            fetchedAt: $now,
            discordLinked: true,
        );
    }
}
