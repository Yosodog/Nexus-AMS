<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FinalizeNationSyncJob;
use App\Jobs\SyncNationsJob;
use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NationSyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_uses_raw_projection_and_bulk_persists_a_page(): void
    {
        Http::fake([
            '*' => Http::response($this->nationResponse([$this->nationPayload(1001)])),
        ]);
        Log::shouldReceive('info')
            ->once()
            ->with('Nation sync page completed', Mockery::on(function (array $context): bool {
                return $context['page'] === 1
                    && $context['nation_count'] === 1
                    && $context['city_count'] === 1
                    && $context['api_ms'] >= 0
                    && $context['transform_ms'] >= 0
                    && $context['database_ms'] >= 0
                    && $context['total_ms'] >= 0;
            }));
        DB::enableQueryLog();

        (new SyncNationsJob(1, 100))->handle();

        $writeQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert into'));

        $this->assertCount(4, $writeQueries);
        $this->assertDatabaseHas('nations', [
            'id' => 1001,
            'war_policy' => 'PIRATE',
            'domestic_policy' => 'URBANIZATION',
            'vacation_mode_turns' => 65000,
            'vip' => 0,
            'commendations' => 0,
            'denouncements' => 0,
        ]);
        $this->assertDatabaseHas('nation_resources', [
            'nation_id' => 1001,
            'credits' => 0,
        ]);
        $this->assertDatabaseHas('nation_military', [
            'nation_id' => 1001,
            'soldiers_today' => 0,
            'soldier_casualties' => 0,
        ]);
        $this->assertDatabaseHas('cities', [
            'id' => 3001,
            'nation_id' => 1001,
            'nuke_date' => '2026-03-01',
        ]);

        Http::assertSent(function ($request): bool {
            $query = (string) $request->data()['query'];

            return str_contains($query, 'project_bits')
                && str_contains($query, 'cities')
                && ! str_contains($query, 'iron_works')
                && ! str_contains($query, 'paginatorInfo');
        });
    }

    public function test_job_restores_soft_deleted_rows_returned_by_the_api(): void
    {
        Http::fake([
            '*' => Http::response($this->nationResponse([$this->nationPayload(1001)])),
        ]);

        (new SyncNationsJob(1, 100))->handle();

        City::query()->findOrFail(3001)->delete();
        NationResources::query()->where('nation_id', 1001)->firstOrFail()->delete();
        NationMilitary::query()->where('nation_id', 1001)->firstOrFail()->delete();
        Nation::query()->findOrFail(1001)->delete();

        (new SyncNationsJob(1, 100))->handle();

        $this->assertNull(Nation::withTrashed()->findOrFail(1001)->deleted_at);
        $this->assertNull(NationResources::withTrashed()->where('nation_id', 1001)->firstOrFail()->deleted_at);
        $this->assertNull(NationMilitary::withTrashed()->where('nation_id', 1001)->firstOrFail()->deleted_at);
        $this->assertNull(City::withTrashed()->findOrFail(3001)->deleted_at);
    }

    public function test_empty_page_is_reported_as_a_failed_sync(): void
    {
        Http::fake([
            '*' => Http::response($this->nationResponse([])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nation sync page 4 returned no records.');

        (new SyncNationsJob(4, 100))->handle();
    }

    public function test_finalizer_soft_deletes_children_before_stale_nations(): void
    {
        Http::fake([
            '*' => Http::response($this->nationResponse([
                $this->nationPayload(1001),
                $this->nationPayload(1002),
            ])),
        ]);
        (new SyncNationsJob(1, 100))->handle();

        Nation::query()->whereKey(1001)->update(['updated_at' => now()->subDays(31)]);
        Nation::query()->whereKey(1002)->update(['updated_at' => now()->subDay()]);

        $batchId = 'nation-sync-finalizer';
        $batch = new Batch(
            Mockery::mock(QueueFactory::class),
            Mockery::mock(BatchRepository::class),
            $batchId,
            'Nation Sync',
            1,
            0,
            0,
            [],
            ['mode' => 'manual'],
            CarbonImmutable::now(),
        );
        Bus::shouldReceive('findBatch')->once()->with($batchId)->andReturn($batch);

        (new FinalizeNationSyncJob($batchId))->handle();

        $this->assertSoftDeleted('nations', ['id' => 1001]);
        $this->assertSoftDeleted('nation_resources', ['nation_id' => 1001]);
        $this->assertSoftDeleted('nation_military', ['nation_id' => 1001]);
        $this->assertSoftDeleted('cities', ['id' => 3001]);
        $this->assertNotSoftDeleted('nations', ['id' => 1002]);
        $this->assertNotSoftDeleted('cities', ['id' => 3002]);
    }

    /**
     * @param  list<array<string, mixed>>  $nations
     * @return array<string, mixed>
     */
    private function nationResponse(array $nations): array
    {
        return [
            'data' => [
                'nations' => [
                    'data' => $nations,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nationPayload(int $id): array
    {
        return [
            'id' => $id,
            'alliance_id' => null,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'nation_name' => "Nation {$id}",
            'leader_name' => "Leader {$id}",
            'continent' => 'NA',
            'war_policy' => 'PIRATE',
            'domestic_policy' => 'URBANIZATION',
            'war_policy_turns' => 4,
            'domestic_policy_turns' => 5,
            'color' => 'blue',
            'num_cities' => 1,
            'score' => 1234.5,
            'update_tz' => 0,
            'population' => 100000,
            'flag' => null,
            'vacation_mode_turns' => 70000,
            'beige_turns' => 0,
            'espionage_available' => true,
            'discord' => null,
            'discord_id' => null,
            'turns_since_last_city' => 1,
            'turns_since_last_project' => 2,
            'projects' => 3,
            'project_bits' => '101',
            'wars_won' => 4,
            'wars_lost' => 5,
            'tax_id' => null,
            'alliance_seniority' => 6,
            'gross_national_income' => 1000.5,
            'gross_domestic_product' => 900.5,
            'vip' => null,
            'commendations' => null,
            'denouncements' => null,
            'offensive_wars_count' => 1,
            'defensive_wars_count' => 2,
            'money_looted' => 50.5,
            'total_infrastructure_destroyed' => 60.5,
            'total_infrastructure_lost' => 70.5,
            'money' => 100.5,
            'coal' => 1.0,
            'oil' => 2.0,
            'uranium' => 3.0,
            'iron' => 4.0,
            'bauxite' => 5.0,
            'lead' => 6.0,
            'gasoline' => 7.0,
            'munitions' => 8.0,
            'steel' => 9.0,
            'aluminum' => 10.0,
            'food' => 11.0,
            'credits' => null,
            'soldiers' => 100,
            'tanks' => 20,
            'aircraft' => 30,
            'ships' => 4,
            'missiles' => 2,
            'nukes' => 1,
            'spies' => 50,
            'soldiers_today' => null,
            'tanks_today' => null,
            'aircraft_today' => null,
            'ships_today' => null,
            'missiles_today' => null,
            'nukes_today' => null,
            'spies_today' => null,
            'soldier_casualties' => null,
            'soldier_kills' => null,
            'tank_casualties' => null,
            'tank_kills' => null,
            'aircraft_casualties' => null,
            'aircraft_kills' => null,
            'ship_casualties' => null,
            'ship_kills' => null,
            'missile_casualties' => null,
            'missile_kills' => null,
            'nuke_casualties' => null,
            'nuke_kills' => null,
            'spy_casualties' => null,
            'spy_kills' => null,
            'spy_attacks' => null,
            'cities' => [$this->cityPayload($id)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cityPayload(int $nationId): array
    {
        return [
            'id' => $nationId + 2000,
            'nation_id' => $nationId,
            'name' => "Capital {$nationId}",
            'date' => '2025-01-01',
            'nuke_date' => '2026-03-01T00:00:00+00:00',
            'infrastructure' => 1500.5,
            'land' => 2000.5,
            'powered' => true,
            'oil_power' => 1,
            'wind_power' => 2,
            'coal_power' => 3,
            'nuclear_power' => 4,
            'coal_mine' => 5,
            'oil_well' => 6,
            'uranium_mine' => 7,
            'barracks' => 8,
            'farm' => 9,
            'police_station' => 10,
            'hospital' => 11,
            'recycling_center' => 12,
            'subway' => 13,
            'supermarket' => 14,
            'bank' => 15,
            'shopping_mall' => 16,
            'stadium' => 17,
            'lead_mine' => 18,
            'iron_mine' => 19,
            'bauxite_mine' => 20,
            'oil_refinery' => 21,
            'aluminum_refinery' => 22,
            'steel_mill' => 23,
            'munitions_factory' => 24,
            'factory' => 25,
            'hangar' => 26,
            'drydock' => 27,
        ];
    }
}
