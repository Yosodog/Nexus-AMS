<?php

namespace Tests\Feature\Admin;

use App\Models\Alliance;
use App\Models\City;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CityIndexPerformanceTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    public function test_city_index_paginates_rows_while_summarizing_the_full_roster(): void
    {
        $alliance = Alliance::factory()->create();
        config(['services.pw.alliance_id' => $alliance->id]);
        app(AllianceMembershipService::class)->clear();

        $nation = Nation::factory()->for($alliance)->create();
        City::query()->insert(
            collect(range(1, 101))
                ->map(fn (int $index): array => $this->cityAttributes($nation, $index))
                ->all(),
        );

        $response = $this->actingAs($this->createAdmin())
            ->get(route('admin.cities.index'));

        $response
            ->assertOk()
            ->assertViewHas('cities', fn (LengthAwarePaginator $cities): bool => $cities->count() === 100
                && $cities->total() === 101
                && $cities->lastPage() === 2)
            ->assertViewHas('summary', fn (array $summary): bool => $summary['total_cities'] === 101
                && $summary['powered_cities'] === 50
                && $summary['unpowered_cities'] === 51
                && $summary['misaligned_infrastructure'] === 1
                && $summary['misaligned_land'] === 0);

        $this->get(route('admin.cities.index', ['sort' => 'infrastructure', 'direction' => 'desc']))
            ->assertOk()
            ->assertViewHas('cities', fn (LengthAwarePaginator $cities): bool => $cities->first()?->name === 'City 101')
            ->assertViewHas('sort', 'infrastructure')
            ->assertViewHas('direction', 'desc');

        $this->get(route('admin.cities.index', ['sort' => 'nation', 'direction' => 'asc']))
            ->assertOk();

        $this->get(route('admin.cities.index', ['sort' => 'alliance', 'direction' => 'asc']))
            ->assertOk();
    }

    private function createAdmin(): User
    {
        $admin = $this->createVerifiedAdmin(['nation_id' => 990001]);
        $this->attachDiscordAccount($admin, ['discord_id' => '1990001']);

        return $this->grantPermissions($admin, ['view-members']);
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function cityAttributes(Nation $nation, int $index): array
    {
        return [
            'id' => 10000 + $index,
            'nation_id' => $nation->id,
            'name' => "City {$index}",
            'date' => now()->subDays($index)->toDateString(),
            'infrastructure' => $index === 101 ? 1510 : 1500,
            'land' => 2000,
            'powered' => $index % 2 === 0,
            'oil_power' => 0,
            'wind_power' => 0,
            'coal_power' => 0,
            'nuclear_power' => 0,
            'coal_mine' => 0,
            'oil_well' => 0,
            'uranium_mine' => 0,
            'lead_mine' => 0,
            'iron_mine' => 0,
            'bauxite_mine' => 0,
            'barracks' => 0,
            'farm' => 0,
            'police_station' => 0,
            'hospital' => 0,
            'recycling_center' => 0,
            'subway' => 0,
            'supermarket' => 0,
            'bank' => 0,
            'shopping_mall' => 0,
            'stadium' => 0,
            'oil_refinery' => 0,
            'aluminum_refinery' => 0,
            'steel_mill' => 0,
            'munitions_factory' => 0,
            'factory' => 0,
            'hangar' => 0,
            'drydock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
