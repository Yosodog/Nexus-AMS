<?php

namespace Tests\Unit\Raids;

use App\Models\RaidModelParameter;
use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidActivity;
use App\Services\RaidStockpileEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\TestCase;

class RaidStockpileEstimatorTest extends TestCase
{
    use BuildsRaidFixtures;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-20 12:00:00');
        $this->travelTo($this->now);
    }

    public function test_loot_baseline_grows_by_retained_production(): void
    {
        $profile = $this->profile(['money' => 1_000_000], ['money' => 100_000]);

        $projection = $this->estimator()->project($profile, $this->now);

        $this->assertSame(1_300_000.0, $projection['resources']['money']);
        $this->assertSame(1.0, $projection['retention']);
        $this->assertSame(3.0, $projection['days']);
        $this->assertNull($projection['manufacturing_stopped_after_days']);
    }

    public function test_consumption_is_not_scaled_by_retention_and_never_goes_negative(): void
    {
        $profile = $this->profile(
            ['money' => 1_000, 'food' => 1_000, 'oil' => 100],
            ['money' => 1_000, 'food' => -100, 'oil' => -100],
            lastActive: $this->now->subHours(2),
        );

        $projection = $this->estimator()->project($profile, $this->now);

        $this->assertSame(0.25, $projection['retention']);
        $this->assertSame(1_750.0, $projection['resources']['money']);
        $this->assertSame(700.0, $projection['resources']['food']);
        $this->assertSame(0.0, $projection['resources']['oil']);
    }

    public function test_manufacturing_stops_after_a_consumed_raw_input_runs_out(): void
    {
        $profile = $this->profile(['iron' => 150], ['iron' => -100, 'steel' => 50]);

        $projection = $this->estimator()->project($profile, $this->now);

        $this->assertSame(0.0, $projection['resources']['iron']);
        $this->assertSame(100.0, $projection['resources']['steel']);
        $this->assertSame(2.0, $projection['manufacturing_stopped_after_days']);
    }

    public function test_projection_is_capped_at_the_maximum_projection_days(): void
    {
        $profile = $this->profile(['money' => 0], ['money' => 1_000], baselineAt: $this->now->subDays(100));

        $projection = $this->estimator()->project($profile, $this->now);

        $this->assertSame(60.0, $projection['days']);
        $this->assertSame(60_000.0, $projection['resources']['money']);
    }

    public function test_retention_blends_measured_samples_with_the_activity_prior(): void
    {
        $profile = $this->profile([], [], lastActive: $this->now->subDays(10));
        $profile->retention_observed = 0.5;
        $profile->retention_samples = 2;

        $this->assertSame(0.75, $this->estimator()->effectiveRetention($profile, $this->now));

        $profile->retention_samples = 0;

        $this->assertSame(1.0, $this->estimator()->effectiveRetention($profile, $this->now));
    }

    public function test_interval_and_bias_use_defaults_without_calibration(): void
    {
        $estimator = $this->estimator();

        $this->assertSame(['low' => 0.7, 'high' => 1.3], $estimator->intervalFactors($this->profile(), $this->now));
        $this->assertSame(['low' => 0.5, 'high' => 1.6], $estimator->intervalFactors($this->profile(baselineAt: $this->now->subDays(10)), $this->now));
        $this->assertSame(['low' => 0.2, 'high' => 2.5], $estimator->intervalFactors($this->profile(kind: RaidTargetProfile::BASELINE_PRODUCTION_ONLY), $this->now));
        $this->assertSame(1.0, $estimator->biasMultiplier($this->profile(), $this->now));
    }

    public function test_calibrated_parameters_override_defaults_and_bias_is_clamped(): void
    {
        RaidModelParameter::query()->create([
            'key' => RaidModelParameter::INTERVAL_FACTORS,
            'value' => ['loot:0_7d' => ['low' => 0.4, 'high' => 1.9]],
            'computed_at' => $this->now,
        ]);
        RaidModelParameter::query()->create([
            'key' => RaidModelParameter::BIAS_MULTIPLIERS,
            'value' => ['loot:inactive' => 10, 'loot:active' => 0.5],
            'computed_at' => $this->now,
        ]);
        $estimator = $this->estimator();
        $inactive = $this->profile(['money' => 1_000]);

        $this->assertSame(['low' => 0.4, 'high' => 1.9], $estimator->intervalFactors($inactive, $this->now));
        $this->assertSame(4.0, $estimator->biasMultiplier($inactive, $this->now));
        $this->assertSame(4_000.0, $estimator->project($inactive, $this->now)['resources']['money']);
        $this->assertSame(1_000.0, $estimator->project($inactive, $this->now, applyBias: false)['resources']['money']);
        $this->assertSame(0.5, $estimator->biasMultiplier($this->profile(lastActive: $this->now->subHour()), $this->now));
    }

    #[DataProvider('activityBoundaries')]
    public function test_activity_bucket_boundaries(?int $hoursAgo, string $expected): void
    {
        $at = CarbonImmutable::parse('2026-09-20 12:00:00');

        $this->assertSame($expected, RaidActivity::bucket($hoursAgo === null ? null : $at->subHours($hoursAgo), $at));
    }

    /** @return iterable<string, array{int|null, string}> */
    public static function activityBoundaries(): iterable
    {
        yield 'just active' => [23, 'active'];
        yield 'one day' => [24, 'recent'];
        yield 'under three days' => [71, 'recent'];
        yield 'three days' => [72, 'idle'];
        yield 'under a week' => [167, 'idle'];
        yield 'one week' => [168, 'inactive'];
        yield 'under thirty days' => [719, 'inactive'];
        yield 'thirty days' => [720, 'abandoned'];
        yield 'never seen' => [null, 'abandoned'];
    }

    /**
     * @param  array<string, float|int>  $baseline
     * @param  array<string, float|int>  $dailyNet
     */
    private function profile(
        array $baseline = [],
        array $dailyNet = [],
        ?CarbonImmutable $lastActive = null,
        ?CarbonImmutable $baselineAt = null,
        string $kind = RaidTargetProfile::BASELINE_LOOT,
    ): RaidTargetProfile {
        $attributes = [
            'nation_id' => 1,
            'baseline_kind' => $kind,
            'baseline_at' => $baselineAt ?? $this->now->subDays(3),
            'last_active' => $lastActive ?? $this->now->subDays(10),
            'retention_observed' => null,
            'retention_samples' => 0,
        ];

        foreach ($this->raidResources($baseline) as $resource => $amount) {
            $attributes['baseline_'.$resource] = $amount;
        }

        foreach ($this->raidResources($dailyNet) as $resource => $amount) {
            $attributes['daily_net_'.$resource] = $amount;
        }

        return new RaidTargetProfile($attributes);
    }

    private function estimator(): RaidStockpileEstimator
    {
        $this->app->forgetScopedInstances();

        return app(RaidStockpileEstimator::class);
    }
}
