<?php

namespace Tests\Feature\Services;

use App\Exceptions\ProfitabilityContextUnavailable;
use App\Jobs\RefreshNationProfitabilitySnapshotJob;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationProfitabilitySnapshot;
use App\Models\RadiationSnapshot;
use App\Services\Economy\EconomyRules;
use App\Services\NationProfitabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ProfitabilityContextAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-02 18:00:00 UTC');
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function zero_values_and_the_six_hour_boundary_are_valid(): void
    {
        $nation = $this->nation([
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now()->subHours(6),
        ]);

        app(NationProfitabilityService::class)->assertCalculationContextAvailable(
            $nation,
            $this->radiationSnapshot()
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function economy_context_older_than_six_hours_is_unavailable(): void
    {
        $nation = $this->nation([
            'economy_context_synced_at' => now()->subHours(6)->subSecond(),
        ]);

        $this->expectException(ProfitabilityContextUnavailable::class);
        $this->expectExceptionMessage("Economy context is stale for nation {$nation->id}.");

        app(NationProfitabilityService::class)->assertCalculationContextAvailable(
            $nation,
            $this->radiationSnapshot()
        );
    }

    #[Test]
    public function null_values_are_missing_while_pinned_old_world_snapshots_remain_usable(): void
    {
        $nation = $this->nation([
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => null,
            'economy_context_synced_at' => now(),
        ]);
        $pinnedSnapshot = $this->radiationSnapshot([
            'snapshot_at' => now()->subDay(),
        ]);

        $this->expectException(ProfitabilityContextUnavailable::class);

        app(NationProfitabilityService::class)->assertCalculationContextAvailable(
            $nation,
            $pinnedSnapshot
        );
    }

    #[Test]
    public function explicitly_pinned_world_snapshot_may_be_old_but_automatic_selection_may_not(): void
    {
        $nation = $this->nation();
        $pinnedSnapshot = $this->radiationSnapshot([
            'snapshot_at' => now()->subHours(4),
        ]);
        $service = app(NationProfitabilityService::class);

        $service->assertCalculationContextAvailable($nation, $pinnedSnapshot);
        $this->addToAssertionCount(1);

        $this->expectException(ProfitabilityContextUnavailable::class);
        $this->expectExceptionMessage('The latest world snapshot is too old to use.');

        $service->assertCalculationContextAvailable(
            $nation,
            $pinnedSnapshot,
            requireFreshWorldSnapshot: true
        );
    }

    #[Test]
    public function world_snapshot_without_a_game_date_is_unavailable_even_when_recent(): void
    {
        $nation = $this->nation();
        $snapshot = $this->radiationSnapshot(['game_date' => null]);

        $this->expectException(ProfitabilityContextUnavailable::class);
        $this->expectExceptionMessage('authoritative game date');

        app(NationProfitabilityService::class)->assertCalculationContextAvailable($nation, $snapshot);
    }

    #[Test]
    public function calculation_using_older_context_cannot_overwrite_newer_context(): void
    {
        $nation = $this->nation([
            'treasure_income_modifier' => 0.01,
            'color_turn_bonus' => 100,
            'economy_context_synced_at' => now()->subHour(),
        ]);
        $calculationInput = $nation->fresh();
        $nation->update([
            'treasure_income_modifier' => 0.02,
            'color_turn_bonus' => 200,
            'economy_context_synced_at' => now(),
        ]);

        $this->expectException(ProfitabilityContextUnavailable::class);
        $this->expectExceptionMessage('changed while calculating');

        DB::transaction(function () use ($calculationInput): void {
            app(NationProfitabilityService::class)
                ->assertPersistedCalculationContextIsCurrent($calculationInput);
        });
    }

    #[Test]
    public function scheduled_refresh_keeps_the_last_valid_result_when_context_is_stale(): void
    {
        $nation = $this->nation([
            'economy_context_synced_at' => now()->subHours(7),
        ]);
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = $this->radiationSnapshot();
        NationProfitabilitySnapshot::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => 777,
            'radiation_snapshot_id' => $worldSnapshot->id,
            'market_price_snapshot_id' => $marketSnapshot->id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'leader_name' => 'Previous Leader',
            'nation_name' => 'Previous Nation',
            'cities' => 1,
            'converted_profit_per_day' => 123456.78,
            'money_profit_per_day' => 100000,
            'resource_profit_per_day' => [],
            'calculated_at' => now()->subHour(),
        ]);

        $this->artisan('profitability:refresh')
            ->expectsOutput('Profitability snapshots were not refreshed. Existing snapshots remain active.')
            ->assertFailed();

        $snapshot = NationProfitabilitySnapshot::query()->where('nation_id', $nation->id)->firstOrFail();
        $this->assertSame(EconomyRules::MODEL_VERSION, $snapshot->model_version);
        $this->assertSame(123456.78, $snapshot->converted_profit_per_day);
        $this->assertSame('Previous Nation', $snapshot->nation_name);
    }

    #[Test]
    public function scheduled_batch_publishes_nothing_when_a_later_nation_has_inconsistent_calendar_data(): void
    {
        $first = $this->nation();
        $second = $this->nation();
        $this->createCity($first, null);
        $this->createCity($second, '2126-09-22');
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = $this->radiationSnapshot(['game_date' => '2126-09-21']);

        foreach ([[$first, 111111.11], [$second, 222222.22]] as [$nation, $profit]) {
            NationProfitabilitySnapshot::query()->create([
                'nation_id' => $nation->id,
                'alliance_id' => 777,
                'radiation_snapshot_id' => $worldSnapshot->id,
                'market_price_snapshot_id' => $marketSnapshot->id,
                'model_version' => EconomyRules::MODEL_VERSION,
                'leader_name' => $nation->leader_name,
                'nation_name' => $nation->nation_name,
                'converted_profit_per_day' => $profit,
                'resource_profit_per_day' => [],
                'calculated_at' => now()->subHour(),
            ]);
        }

        $this->artisan('profitability:refresh')->assertFailed();

        $this->assertSame(
            111111.11,
            NationProfitabilitySnapshot::query()
                ->where('nation_id', $first->id)
                ->firstOrFail()
                ->converted_profit_per_day
        );
        $this->assertSame(
            222222.22,
            NationProfitabilitySnapshot::query()
                ->where('nation_id', $second->id)
                ->firstOrFail()
                ->converted_profit_per_day
        );
    }

    #[Test]
    public function corrected_refresh_republishes_v2_with_reproducible_calendar_context(): void
    {
        $nation = $this->nation();
        City::query()->create([
            'nation_id' => $nation->id,
            'name' => 'Calendar City',
            'date' => '2025-08-02',
            'nuke_date' => null,
            'infrastructure' => 1000,
            'land' => 1000,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'nuclear_power' => 1,
        ]);
        $marketSnapshot = $this->marketSnapshot();
        $worldSnapshot = $this->radiationSnapshot([
            'game_date' => '2126-09-21',
        ]);
        $legacy = NationProfitabilitySnapshot::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => 777,
            'model_version' => 1,
            'leader_name' => 'Legacy Leader',
            'nation_name' => 'Legacy Nation',
            'resource_profit_per_day' => [],
            'calculated_at' => now()->subDay(),
        ]);

        $snapshot = app(NationProfitabilityService::class)->refreshStoredSnapshotForNationId(
            $nation->id,
            $marketSnapshot->id,
            $worldSnapshot->id
        );

        $this->assertNotNull($snapshot);
        $this->assertSame($legacy->id, $snapshot->id);
        $this->assertSame(EconomyRules::MODEL_VERSION, $snapshot->model_version);
        $this->assertSame($worldSnapshot->id, $snapshot->calculation_context['world_snapshot_id']);
        $this->assertSame('2126-09-21', $snapshot->calculation_context['game_date']);
        $this->assertSame(9, $snapshot->calculation_context['season_month']);
        $this->assertSame(
            now()->toIso8601String(),
            CarbonImmutable::parse($snapshot->calculation_context['economy_context_synced_at'])->toIso8601String()
        );
        $this->assertFalse($snapshot->calculation_context['economy_context_stale']);
    }

    #[Test]
    public function queued_context_failure_is_skipped_without_retrying_invalid_inputs(): void
    {
        $service = Mockery::mock(NationProfitabilityService::class);
        $service->shouldReceive('refreshStoredSnapshotForNationId')
            ->once()
            ->with(123, 45, 67)
            ->andThrow(new ProfitabilityContextUnavailable);
        $job = new RefreshNationProfitabilitySnapshotJob(123, 45, 67);

        $job->handle($service);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function queued_unexpected_failure_is_rethrown_for_normal_retry_handling(): void
    {
        $service = Mockery::mock(NationProfitabilityService::class);
        $service->shouldReceive('refreshStoredSnapshotForNationId')
            ->once()
            ->with(123, 45, 67)
            ->andThrow(new RuntimeException('Unexpected failure'));
        $job = new RefreshNationProfitabilitySnapshotJob(123, 45, 67);

        $this->expectException(RuntimeException::class);

        $job->handle($service);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function nation(array $overrides = []): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function radiationSnapshot(array $overrides = []): RadiationSnapshot
    {
        return RadiationSnapshot::query()->create([
            'snapshot_at' => now(),
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
            ...$overrides,
        ]);
    }

    private function createCity(Nation $nation, ?string $nukeDate): City
    {
        return City::query()->create([
            'nation_id' => $nation->id,
            'name' => 'Context City',
            'date' => '2025-08-02',
            'nuke_date' => $nukeDate,
            'infrastructure' => 1000,
            'land' => 1000,
            'powered' => true,
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'nuclear_power' => 1,
        ]);
    }

    private function marketSnapshot(): MarketPriceSnapshot
    {
        $snapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'test completed-market prices',
            'window_started_at' => now()->subDays(7),
            'window_ended_at' => now(),
            'calculated_at' => now(),
        ]);
        $snapshot->items()->createMany(collect(EconomyRules::TRADE_RESOURCES)
            ->map(fn (string $resource): array => [
                'resource' => $resource,
                'acquisition_price' => 100,
                'liquidation_price' => 90,
            ])->all());

        return $snapshot;
    }
}
