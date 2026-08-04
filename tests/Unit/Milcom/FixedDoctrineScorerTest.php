<?php

namespace Tests\Unit\Milcom;

use App\Domain\Milcom\FixedDoctrineScorer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Milcom\Concerns\BuildsReadinessProfiles;

class FixedDoctrineScorerTest extends TestCase
{
    use BuildsReadinessProfiles;

    private FixedDoctrineScorer $scorer;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new FixedDoctrineScorer;
        $this->now = new DateTimeImmutable('2026-08-02T12:00:00+00:00');
    }

    #[Test]
    public function fixed_v1_applies_the_documented_factor_weights(): void
    {
        $friendly = $this->profile();
        $target = $this->profile(['nationId' => 2]);
        $assessment = $this->scorer->assess(
            $friendly,
            $target,
            $this->now,
        );
        $allocationEdge = $this->scorer->allocationEdge(99, $friendly, $target, $this->now);

        $this->assertSame([
            'air' => 50.0,
            'ground' => 50.0,
            'naval' => 50.0,
            'readiness' => 100.0,
            'tactical_fit' => 100.0,
            'activity' => 100.0,
        ], $assessment->factors);
        $this->assertSame(65.0, $assessment->score);
        $this->assertEqualsWithDelta(
            ($assessment->factors['air'] * 0.40)
                + ($assessment->factors['ground'] * 0.20)
                + ($assessment->factors['naval'] * 0.10)
                + ($assessment->factors['readiness'] * 0.15)
                + ($assessment->factors['tactical_fit'] * 0.10)
                + ($assessment->factors['activity'] * 0.05),
            $assessment->score,
            0.01,
        );
        $this->assertSame('fixed-v1', $assessment->explanation['doctrine_version']);
        $this->assertSame($assessment->score, $allocationEdge->score);
        $this->assertSame($assessment->confidence, $allocationEdge->confidence);
    }

    #[Test]
    public function confidence_is_separate_and_reflects_completeness_and_freshness(): void
    {
        $completeFresh = $this->scorer->assess(
            $this->profile(),
            $this->profile(['nationId' => 2]),
            $this->now,
        );
        $completeOlder = $this->scorer->assess(
            $this->profile(),
            $this->profile([
                'nationId' => 2,
                'fetchedAt' => $this->now->modify('-30 minutes'),
            ]),
            $this->now,
        );
        $incompleteFresh = $this->scorer->assess(
            $this->profile(['aircraft' => null]),
            $this->profile(['nationId' => 2]),
            $this->now,
        );

        $this->assertSame(100.0, $completeFresh->confidence);
        $this->assertSame(90.0, $completeOlder->confidence);
        $this->assertSame(50.0, $incompleteFresh->confidence);
        $this->assertArrayHasKey('air', $incompleteFresh->factors);
    }

    #[Test]
    public function strong_conventional_overmatch_improves_instead_of_penalizing_the_pair_score(): void
    {
        $equal = $this->scorer->assess(
            $this->profile(),
            $this->profile(['nationId' => 2]),
            $this->now,
        );
        $overmatch = $this->scorer->assess(
            $this->profile(),
            $this->profile([
                'nationId' => 2,
                'soldiers' => 75000,
                'tanks' => 6250,
                'aircraft' => 375,
                'ships' => 75,
            ]),
            $this->now,
        );

        $this->assertGreaterThan($equal->score, $overmatch->score);
        $this->assertGreaterThan($equal->factors['air'], $overmatch->factors['air']);
        $this->assertGreaterThan($equal->factors['ground'], $overmatch->factors['ground']);
        $this->assertGreaterThan($equal->factors['naval'], $overmatch->factors['naval']);
        $this->assertSame(
            'Strong matchups are kept. Team building decides where rare attackers help most.',
            $overmatch->explanation['overmatch_policy'],
        );
    }

    #[Test]
    public function missiles_and_nukes_are_explained_but_never_change_the_score(): void
    {
        $withoutStrategicWeapons = $this->scorer->assess(
            $this->profile(['missiles' => 0, 'nukes' => 0]),
            $this->profile(['nationId' => 2, 'missiles' => 0, 'nukes' => 0]),
            $this->now,
        );
        $withStrategicWeapons = $this->scorer->assess(
            $this->profile(['missiles' => 999, 'nukes' => 777]),
            $this->profile(['nationId' => 2, 'missiles' => 555, 'nukes' => 333]),
            $this->now,
        );

        $this->assertSame($withoutStrategicWeapons->score, $withStrategicWeapons->score);
        $this->assertSame($withoutStrategicWeapons->factors, $withStrategicWeapons->factors);
        $this->assertFalse($withStrategicWeapons->explanation['missiles_and_nukes']['scored']);
        $this->assertSame([
            'missiles' => 999,
            'nukes' => 777,
        ], $withStrategicWeapons->explanation['missiles_and_nukes']['friendly']);
        $this->assertSame([
            'missiles' => 555,
            'nukes' => 333,
        ], $withStrategicWeapons->explanation['missiles_and_nukes']['target']);
    }
}
