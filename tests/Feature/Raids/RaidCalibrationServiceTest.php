<?php

namespace Tests\Feature\Raids;

use App\Models\RaidLootEvent;
use App\Models\RaidModelParameter;
use App\Services\Raids\RaidCalibrationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $eventId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    }

    public function test_only_buckets_with_enough_samples_are_calibrated(): void
    {
        foreach (range(1, 30) as $step) {
            $this->backtest($step / 10, 'loot', 48, 'inactive');
        }

        foreach (range(1, 5) as $step) {
            $this->backtest(3.0, 'production_only', 400, 'active');
        }

        $this->backtest(50.0, 'loot', 48, 'inactive', now()->subDays(90));

        $summary = app(RaidCalibrationService::class)->calibrate();

        $this->assertSame(35, $summary['sample_count']);
        $this->assertSame(['loot:0_7d' => ['low' => 0.2, 'high' => 1.8]], $summary['interval_factors']);
        $this->assertSame(['loot:inactive' => 1.5], $summary['bias_multipliers']);
        $this->assertSame(['loot:0_7d' => ['low' => 0.2, 'high' => 1.8]], RaidModelParameter::query()->findOrFail('interval_factors')->value);
        $this->assertSame(35, RaidModelParameter::query()->findOrFail('bias_multipliers')->sample_count);
    }

    public function test_bias_compounds_the_stored_multiplier_and_keeps_other_buckets(): void
    {
        RaidModelParameter::query()->create([
            'key' => RaidModelParameter::BIAS_MULTIPLIERS,
            'value' => ['loot:inactive' => 2.0, 'loot:active' => 0.8],
            'computed_at' => now()->subWeek(),
        ]);

        foreach (range(1, 30) as $step) {
            $this->backtest($step / 10, 'loot', 48, 'inactive');
        }

        app(RaidCalibrationService::class)->calibrate();

        $this->assertEquals(
            ['loot:inactive' => 3.0, 'loot:active' => 0.8],
            RaidModelParameter::query()->findOrFail('bias_multipliers')->value,
        );
    }

    public function test_bias_is_clamped(): void
    {
        foreach (range(1, 30) as $step) {
            $this->backtest(9.0, 'loot', 48, 'abandoned');
        }

        $this->assertSame(['loot:abandoned' => 4.0], app(RaidCalibrationService::class)->calibrate()['bias_multipliers']);
    }

    public function test_nothing_is_stored_without_enough_backtests(): void
    {
        $this->backtest(2.0, 'loot', 48, 'idle');

        $this->artisan('raids:calibrate')->assertSuccessful();

        $this->assertDatabaseCount('raid_model_parameters', 0);
    }

    private function backtest(float $ratio, string $kind, float $ageHours, string $activity, ?CarbonInterface $at = null): void
    {
        RaidLootEvent::factory()->create([
            'id' => $this->eventId++,
            'occurred_at' => $at ?? now()->subDays(3),
            'predicted_value' => 1_000_000,
            'revealed_value' => 1_000_000 * $ratio,
            'prediction_evidence_kind' => $kind,
            'prediction_age_hours' => $ageHours,
            'prediction_activity_bucket' => $activity,
        ]);
    }
}
