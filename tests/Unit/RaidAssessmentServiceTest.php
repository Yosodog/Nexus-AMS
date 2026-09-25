<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\RaidLootEvent;
use App\Models\RaidPrediction;
use App\Models\War;
use App\Services\RaidAssessmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RaidAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::forget('alliances:membership:ids');

        parent::tearDown();
    }

    public function test_partial_terminal_outcomes_are_excluded_from_accuracy_samples(): void
    {
        RaidPrediction::query()->create([
            'war_id' => 2001,
            'attacker_nation_id' => 101,
            'target_nation_id' => 202,
            'declared_at' => now()->subDay(),
            'captured_at' => now()->subDay(),
            'capture_status' => RaidPrediction::CAPTURE_READY,
            'expected_net' => 100,
            'actual_net' => null,
            'outcome_status' => RaidPrediction::OUTCOME_WON,
            'outcome_metadata' => ['evidence_status' => 'incomplete'],
        ]);

        $report = app(RaidAssessmentService::class)->assess(now()->subDays(30), now());

        $this->assertSame(1, $report['capture']['total']);
        $this->assertSame(0, $report['sample_count']);
        $this->assertNull($report['metrics']['signed_error']);
        $this->assertSame(100.0, $report['capture_readiness_percent']);
        $this->assertSame('unknown', $report['capture_coverage']['status']);
        $this->assertNull($report['capture_coverage']['percent']);
    }

    public function test_prediction_range_uses_the_low_and_high_expected_net(): void
    {
        RaidPrediction::query()->create([
            'war_id' => 2002,
            'attacker_nation_id' => 101,
            'target_nation_id' => 202,
            'declared_at' => now()->subDay(),
            'captured_at' => now()->subDay(),
            'capture_status' => RaidPrediction::CAPTURE_READY,
            'expected_net' => 100,
            'expected_net_low' => 50,
            'expected_net_high' => 200,
            'actual_net' => 300,
            'outcome_status' => RaidPrediction::OUTCOME_WON,
            'outcome_metadata' => ['evidence_status' => 'complete'],
        ]);

        $report = app(RaidAssessmentService::class)->assess(now()->subDays(30), now());

        $this->assertSame(0, $report['metrics']['range_coverage']['covered']);
        $this->assertSame(1, $report['metrics']['range_coverage']['eligible']);
        $this->assertSame(0.0, $report['metrics']['range_coverage']['percent']);
    }

    public function test_ranking_buckets_victory_calibration_and_snapshot_breakdowns(): void
    {
        foreach ([[1, 500, 600, 0.9, 'won'], [3, 400, 100, 0.8, 'lost'], [8, 300, 350, 0.6, 'won'], [null, 200, 50, 0.5, 'lost']] as $index => [$rank, $expected, $actual, $victory, $status]) {
            RaidPrediction::query()->create([
                'war_id' => 4000 + $index,
                'attacker_nation_id' => 101,
                'target_nation_id' => 300 + $index,
                'declared_at' => now()->subDay(),
                'captured_at' => now()->subDay(),
                'capture_status' => RaidPrediction::CAPTURE_READY,
                'expected_net' => $expected,
                'actual_net' => $actual,
                'victory_probability' => $victory,
                'finder_rank' => $rank,
                'target_snapshot' => ['activity_bucket' => 'idle'],
                'context_snapshot' => ['competing_attackers' => $index],
                'outcome_status' => $status,
                'outcome_metadata' => [
                    'evidence_status' => 'complete',
                    'stockpile_estimation' => ['stockpile_error' => ['money' => 100.0 * ($index + 1)]],
                ],
            ]);
        }

        $report = app(RaidAssessmentService::class)->assess(now()->subDays(30), now());

        $this->assertSame(2, $report['ranking']['buckets']['1_5']['count']);
        $this->assertSame(450.0, $report['ranking']['buckets']['1_5']['mean_expected_net']);
        $this->assertSame(350.0, $report['ranking']['buckets']['1_5']['mean_actual_net']);
        $this->assertSame(1, $report['ranking']['buckets']['6_10']['count']);
        $this->assertSame(0, $report['ranking']['buckets']['11_25']['count']);
        $this->assertSame(1, $report['ranking']['buckets']['not_from_finder']['count']);
        $this->assertSame(66.67, $report['ranking']['top5_share']);
        $this->assertSame(['sample_count' => 4, 'predicted_percent' => 70.0, 'actual_percent' => 50.0], $report['metrics']['victory_calibration']);
        $this->assertSame(['money' => 250.0], $report['metrics']['stockpile_errors']);
        $this->assertSame(['idle'], array_keys($report['breakdowns']['activity']));
        $this->assertSame(['high', 'low', 'medium', 'none'], array_keys($report['breakdowns']['competition']));
    }

    public function test_estimator_accuracy_reports_world_backtests(): void
    {
        foreach ([[100, 100, 'loot', 24, 'idle'], [100, 150, 'loot', 24, 'idle'], [100, 20, 'loot', 400, 'active'], [100, 300, 'production_only', 24, 'inactive']] as $index => [$predicted, $revealed, $kind, $age, $activity]) {
            RaidLootEvent::factory()->create([
                'id' => 700 + $index,
                'occurred_at' => now()->subDays(2),
                'predicted_value' => $predicted,
                'revealed_value' => $revealed,
                'prediction_evidence_kind' => $kind,
                'prediction_age_hours' => $age,
                'prediction_activity_bucket' => $activity,
            ]);
        }
        RaidLootEvent::factory()->create(['id' => 799, 'occurred_at' => now()->subDays(2), 'predicted_value' => null, 'revealed_value' => 100]);

        $estimator = app(RaidAssessmentService::class)->assess(now()->subDays(30), now())['estimator'];

        $this->assertSame(4, $estimator['sample_count']);
        $this->assertSame(1.25, $estimator['median_ratio']);
        $this->assertSame(50.0, $estimator['median_absolute_percent_error']);
        $this->assertSame(25.0, $estimator['interval_coverage_percent']);
        $this->assertSame(['loot', 'production_only'], array_keys($estimator['breakdowns']['evidence_kind']));
        $this->assertSame(3, $estimator['breakdowns']['evidence_kind']['loot']['sample_count']);
        $this->assertSame(['0_7d', '7_30d'], array_keys($estimator['breakdowns']['age']));
        $this->assertSame(['active', 'idle', 'inactive'], array_keys($estimator['breakdowns']['activity']));
        $this->assertNull($estimator['model_parameters_computed_at']);
    }

    public function test_capture_coverage_counts_known_qualifying_wars_and_excludes_other_wars(): void
    {
        Cache::forever('alliances:membership:ids', [777]);
        $from = CarbonImmutable::parse('2026-09-01 00:00:00');
        $to = CarbonImmutable::parse('2026-09-30 00:00:00');

        $capturedWar = $this->createWar(3001, '2026-09-10 00:00:00', 101, 777, 'MEMBER', 'RAID');
        $pendingWar = $this->createWar(3002, '2026-09-11 00:00:00', 101, 777, 'MEMBER', 'RAID');
        $this->createWar(3003, '2026-09-12 00:00:00', 101, 777, 'MEMBER', 'RAID');
        $this->createWar(3004, '2026-09-13 00:00:00', 101, 888, 'MEMBER', 'RAID');
        $this->createWar(3005, '2026-09-14 00:00:00', 101, 777, 'MEMBER', 'ORDINARY');
        $this->createWar(3006, '2026-09-15 00:00:00', 101, 777, 'APPLICANT', 'RAID');

        $this->createPrediction($capturedWar, RaidPrediction::CAPTURE_READY, '2026-09-10 00:00:00');
        $this->createPrediction($pendingWar, RaidPrediction::CAPTURE_INCOMPLETE, '2026-09-11 00:00:00');

        $report = app(RaidAssessmentService::class)->assess($from, $to);
        $coverage = $report['capture_coverage'];

        $this->assertSame('known', $coverage['status']);
        $this->assertSame(66.67, $coverage['percent']);
        $this->assertSame(3, $coverage['known_qualifying_declarations']);
        $this->assertSame(2, $coverage['captured_qualifying_declarations']);
        $this->assertSame(1, $coverage['missing_capture_count']);
        $this->assertSame(1, $coverage['pending_capture_count']);
        $this->assertSame('known_world_wars_since_capture_baseline', $coverage['source']);
        $this->assertStringStartsWith('2026-09-10T00:00:00', $coverage['baseline_start']);
    }

    /** @param array<string, mixed> $overrides */
    private function createWar(
        int $id,
        string $date,
        int $attackerId,
        int $allianceId,
        string $position,
        string $warType,
        array $overrides = [],
    ): War {
        return War::query()->create(array_merge([
            'id' => $id,
            'date' => $date,
            'reason' => 'Assessment coverage test',
            'war_type' => $warType,
            'turns_left' => 12,
            'att_id' => $attackerId,
            'att_alliance_id' => $allianceId,
            'att_alliance_position' => $position,
            'def_id' => $id + 1000,
            'def_alliance_id' => null,
            'def_alliance_position' => 'NOALLIANCE',
        ], $overrides));
    }

    private function createPrediction(War $war, string $captureStatus, string $declaredAt): RaidPrediction
    {
        return RaidPrediction::query()->create([
            'war_id' => $war->id,
            'attacker_nation_id' => $war->att_id,
            'target_nation_id' => $war->def_id,
            'declared_at' => $declaredAt,
            'captured_at' => $declaredAt,
            'capture_status' => $captureStatus,
        ]);
    }
}
