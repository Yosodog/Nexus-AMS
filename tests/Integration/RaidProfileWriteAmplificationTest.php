<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\City;
use App\Models\Nation;
use App\Models\RadiationSnapshot;
use App\Models\RaidTargetProfile;
use App\Services\Raids\RaidProfileDirtyMarker;
use App\Services\Raids\RaidTargetProfileBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRaidFixtures;

class RaidProfileWriteAmplificationTest extends MySqlIntegrationTestCase
{
    use BuildsRaidFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        $this->seedRaidMarketPrices(100);
        RadiationSnapshot::query()->create([
            'snapshot_at' => now()->subHour(),
            'game_date' => '2026-06-01',
            ...array_fill_keys(['global', 'north_america', 'south_america', 'europe', 'africa', 'asia', 'australia', 'antarctica'], 0),
        ]);
    }

    public function test_unchanged_rebuilds_and_projections_cost_no_insert_attempts_and_no_redundant_updates(): void
    {
        $ids = collect(range(1, 20))->map(fn (): int => $this->nation()->id)->all();
        app(RaidTargetProfileBuilder::class)->build($ids);

        $clean = $this->handlerWrites(fn () => app(RaidTargetProfileBuilder::class)->build($ids));
        $this->assertSame(['write' => 0, 'update' => 0], $clean);

        app(RaidProfileDirtyMarker::class)->mark($ids);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $remark = $this->handlerWrites(fn () => app(RaidProfileDirtyMarker::class)->mark($ids));
        $this->assertSame(['write' => 0, 'update' => 20], $remark);

        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $dirty = $this->handlerWrites(fn () => $this->artisan('raids:process-profiles')->assertSuccessful());
        $this->assertSame(['write' => 0, 'update' => 20], $dirty);
        $this->assertSame(0, RaidTargetProfile::query()->whereNotNull('dirty_at')->count());

        $projection = $this->handlerWrites(fn () => $this->artisan('raids:project-profiles')->assertSuccessful());
        $this->assertSame(['write' => 0, 'update' => 0], $projection);
    }

    public function test_a_mark_placed_while_a_profile_is_rebuilding_keeps_it_dirty(): void
    {
        $nation = $this->nation(['score' => 1_000]);
        app(RaidTargetProfileBuilder::class)->build([$nation->id]);
        app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $interleaved = false;

        DB::listen(function (QueryExecuted $query) use ($nation, &$interleaved): void {
            if ($interleaved || ! str_contains($query->sql, 'from `nations`')) {
                return;
            }

            $interleaved = true;
            Nation::query()->whereKey($nation->id)->update(['score' => 1_200]);
            app(RaidProfileDirtyMarker::class)->mark([$nation->id]);
        });

        $this->artisan('raids:process-profiles')->assertSuccessful();

        $this->assertTrue($interleaved);
        $this->assertTrue(RaidTargetProfile::query()->findOrFail($nation->id)->dirty_at?->equalTo(now()));

        $this->travel(RaidProfileDirtyMarker::SETTLE_SECONDS)->seconds();
        $this->artisan('raids:process-profiles')->assertSuccessful();

        $profile = RaidTargetProfile::query()->findOrFail($nation->id);
        $this->assertSame(1_200.0, $profile->score);
        $this->assertNull($profile->dirty_at);
    }

    /**
     * InnoDB row inserts (including duplicate-key insert attempts) and row updates the
     * callback caused on this session.
     *
     * @return array{write: int, update: int}
     */
    private function handlerWrites(callable $callback): array
    {
        $before = $this->handlerStatus();
        $callback();
        $after = $this->handlerStatus();

        return [
            'write' => $after['Handler_write'] - $before['Handler_write'],
            'update' => $after['Handler_update'] - $before['Handler_update'],
        ];
    }

    /**
     * @return array{Handler_write: int, Handler_update: int}
     */
    private function handlerStatus(): array
    {
        $status = collect(DB::select("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_write', 'Handler_update')"))
            ->mapWithKeys(fn (object $row): array => [$row->Variable_name => (int) $row->Value]);

        return ['Handler_write' => (int) $status->get('Handler_write'), 'Handler_update' => (int) $status->get('Handler_update')];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nation(array $attributes = []): Nation
    {
        $nation = Nation::factory()->create(['num_cities' => 2, 'created_at' => now()->subYear(), ...$attributes]);

        foreach ([1_000, 2_000] as $index => $infrastructure) {
            City::query()->create([
                'nation_id' => $nation->id,
                'name' => "City {$index}",
                'date' => now()->subYear()->toDateString(),
                'infrastructure' => $infrastructure,
                'land' => 1_500,
                'powered' => true,
                ...array_fill_keys([
                    'oil_power', 'wind_power', 'coal_power', 'uranium_mine', 'barracks', 'police_station',
                    'hospital', 'recycling_center', 'subway', 'supermarket', 'bank', 'shopping_mall', 'stadium',
                    'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery', 'aluminum_refinery', 'steel_mill',
                    'munitions_factory', 'factory', 'hangar', 'drydock', 'coal_mine', 'oil_well',
                ], 0),
                'nuclear_power' => 1,
                'farm' => 5,
            ]);
        }

        return $nation;
    }
}
