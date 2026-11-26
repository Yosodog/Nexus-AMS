<?php

namespace Tests\Concerns;

use App\GraphQL\Models\Alliance as AllianceGraphQL;
use App\GraphQL\Models\City as CityGraphQL;
use App\GraphQL\Models\Nation as NationGraphQL;
use App\Services\PWHelperService;
use Illuminate\Support\Facades\DB;
use stdClass;

trait CapturesApiHydrationWrites
{
    /**
     * Runs the callback and returns every UPDATE statement it issued.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    protected function captureUpdates(callable $callback): array
    {
        $updates = [];

        DB::listen(function ($query) use (&$updates): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update ')) {
                $updates[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $callback();

        return $updates;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $updates
     * @return list<string>
     */
    protected function updatedTables(array $updates): array
    {
        return array_map(
            fn (array $update): string => preg_match('/^update\s+[`"]?(\w+)[`"]?/i', ltrim($update['sql']), $matches) === 1
                ? $matches[1]
                : $update['sql'],
            $updates,
        );
    }

    /**
     * Mirrors the shape of a Politics & War nation payload (all scalar fields, resources, military,
     * military research and project ownership) as it arrives from the API after JSON decoding.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function nationApiJson(int $id, array $overrides = []): stdClass
    {
        $projects = collect(array_keys(PWHelperService::PROJECT_API_FIELDS))
            ->mapWithKeys(fn (string $field): array => [$field => in_array($field, ['iron_works', 'mass_irrigation'], true)])
            ->all();

        return (object) array_merge([
            'id' => (string) $id,
            'alliance_id' => '777',
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => '2',
            'nation_name' => 'Testland',
            'leader_name' => 'Test Leader',
            'continent' => 'na',
            'war_policy' => 'ATTRITION',
            'domestic_policy' => 'MANIFEST_DESTINY',
            'war_policy_turns' => 12,
            'domestic_policy_turns' => 0,
            'color' => 'blue',
            'num_cities' => 21,
            'score' => 2345.67,
            'update_tz' => -5.0,
            'population' => 1234567,
            'flag' => 'https://politicsandwar.com/uploads/flag.png',
            'vacation_mode_turns' => 0,
            'beige_turns' => 3,
            'espionage_available' => true,
            'discord' => 'test#0001',
            'discord_id' => '123456789012345678',
            'turns_since_last_city' => 14,
            'turns_since_last_project' => 40,
            'projects' => 2,
            'project_bits' => '2049',
            'wars_won' => 31,
            'wars_lost' => 4,
            'tax_id' => 88,
            'alliance_seniority' => 120,
            'gross_national_income' => 12345678.9,
            'gross_domestic_product' => 98765432.1,
            'vip' => false,
            'commendations' => 13,
            'denouncements' => 2,
            'offensive_wars_count' => 1,
            'defensive_wars_count' => 0,
            'money_looted' => 1234567.89,
            'total_infrastructure_destroyed' => 45678.9,
            'total_infrastructure_lost' => 1234.5,
            'money' => 98765432.12,
            'coal' => 1234.5,
            'oil' => 2345.67,
            'uranium' => 12.34,
            'iron' => 345.6,
            'bauxite' => 456.78,
            'lead' => 567.89,
            'gasoline' => 1234567.89,
            'munitions' => 7654321.01,
            'steel' => 111.11,
            'aluminum' => 222.22,
            'food' => 3333333.33,
            'credits' => 5,
            'soldiers' => 150000,
            'tanks' => 12000,
            'aircraft' => 1500,
            'ships' => 300,
            'missiles' => 20,
            'nukes' => 3,
            'spies' => 60,
            'soldier_casualties' => 100,
            'soldier_kills' => 200,
            'tank_casualties' => 10,
            'tank_kills' => 20,
            'aircraft_casualties' => 1,
            'aircraft_kills' => 2,
            'ship_casualties' => 0,
            'ship_kills' => 0,
            'missile_casualties' => 0,
            'missile_kills' => 0,
            'nuke_casualties' => 0,
            'nuke_kills' => 0,
            'spy_casualties' => 4,
            'spy_kills' => 5,
            'spy_attacks' => 6,
            'military_research' => (object) [
                'ground_capacity' => 3,
                'ground_cost' => 2,
                'air_capacity' => 1,
                'air_cost' => 0,
                'naval_capacity' => 0,
                'naval_cost' => 4,
            ],
        ], $projects, $overrides);
    }

    protected function nationFromApiJson(stdClass $json): NationGraphQL
    {
        $nation = new NationGraphQL;
        $nation->buildWithJSON($json);

        return $nation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function allianceApiJson(int $id, array $overrides = []): stdClass
    {
        return (object) array_merge([
            'id' => (string) $id,
            'name' => 'Test Alliance',
            'acronym' => 'TA',
            'score' => 123456.78,
            'color' => 'blue',
            'average_score' => 2345.67,
            'accept_members' => true,
            'flag' => 'https://politicsandwar.com/uploads/alliance.png',
            'forum_link' => 'https://forum.example.test',
            'discord_link' => 'https://discord.example.test/invite',
            'wiki_link' => null,
            'rank' => 7,
        ], $overrides);
    }

    protected function allianceFromApiJson(stdClass $json): AllianceGraphQL
    {
        $alliance = new AllianceGraphQL;
        $alliance->buildWithJSON($json);

        return $alliance;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function cityApiJson(int $id, int $nationId, array $overrides = []): stdClass
    {
        $improvements = array_fill_keys([
            'oil_power', 'wind_power', 'coal_power', 'nuclear_power', 'coal_mine', 'oil_well', 'uranium_mine',
            'barracks', 'farm', 'police_station', 'hospital', 'recycling_center', 'subway', 'supermarket', 'bank',
            'shopping_mall', 'stadium', 'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery',
            'aluminum_refinery', 'steel_mill', 'munitions_factory', 'factory', 'hangar', 'drydock',
        ], 0);

        return (object) array_merge($improvements, [
            'id' => (string) $id,
            'nation_id' => (string) $nationId,
            'name' => 'Capital',
            'date' => '2024-03-15',
            'nuke_date' => '-0001-11-30',
            'infrastructure' => 1250.75,
            'land' => 2100.5,
            'powered' => true,
            'coal_power' => 1,
            'farm' => 4,
            'hospital' => 5,
        ], $overrides);
    }

    protected function cityFromApiJson(stdClass $json): CityGraphQL
    {
        $city = new CityGraphQL;
        $city->buildWithJSON($json);

        return $city;
    }

    /**
     * Shape of a war record as delivered by the subscription feed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function warApiPayload(int $id, int $attackerId, int $defenderId, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) $id,
            'date' => '2026-11-20T10:00:00+00:00',
            'end_date' => null,
            'reason' => 'Raid',
            'war_type' => 'RAID',
            'ground_control' => null,
            'air_superiority' => $attackerId,
            'naval_blockade' => null,
            'winner_id' => null,
            'turns_left' => 55,
            'att_id' => (string) $attackerId,
            'att_alliance_id' => null,
            'att_alliance_position' => 'NOALLIANCE',
            'def_id' => (string) $defenderId,
            'def_alliance_id' => null,
            'def_alliance_position' => 'NOALLIANCE',
            'att_points' => 6,
            'def_points' => 3,
            'att_peace' => false,
            'def_peace' => true,
            'att_resistance' => 88,
            'def_resistance' => 70,
            'att_fortify' => true,
            'def_fortify' => false,
            'att_gas_used' => 120.5,
            'def_gas_used' => 80.25,
            'att_mun_used' => 300.75,
            'def_mun_used' => 0.0,
            'att_alum_used' => 0.0,
            'def_alum_used' => 0.0,
            'att_steel_used' => 0.0,
            'def_steel_used' => 0.0,
            'att_infra_destroyed' => 1500.5,
            'def_infra_destroyed' => 0.0,
            'att_money_looted' => 1234567.89,
            'def_money_looted' => 0.0,
            'att_soldiers_lost' => 100,
            'def_soldiers_lost' => 250,
            'att_tanks_lost' => 5,
            'def_tanks_lost' => 12,
            'att_aircraft_lost' => 0,
            'def_aircraft_lost' => 1,
            'att_ships_lost' => 0,
            'def_ships_lost' => 0,
            'att_missiles_used' => 0,
            'def_missiles_used' => 0,
            'att_nukes_used' => 0,
            'def_nukes_used' => 0,
            'att_infra_destroyed_value' => 7654321.25,
            'def_infra_destroyed_value' => 0.0,
        ], $overrides);
    }

    /**
     * Shape of a war attack event as delivered by the subscription feed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function warAttackApiPayload(int $id, int $warId, int $attackerId, int $defenderId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'date' => '2026-11-20T10:05:00+00:00',
            'att_id' => $attackerId,
            'def_id' => $defenderId,
            'type' => 'GROUND',
            'war_id' => $warId,
            'victor' => $attackerId,
            'success' => 3,
            'attcas1' => 120.5,
            'defcas1' => 340.25,
            'attcas2' => 10.0,
            'defcas2' => 20.0,
            'city_id' => null,
            'infra_destroyed' => 45.5,
            'improvements_lost' => 1,
            'money_stolen' => 1234567.89,
            'note' => 'loot',
            'city_infra_before' => 1250.75,
            'infra_destroyed_value' => 98765.43,
            'att_mun_used' => 12.34,
            'def_mun_used' => 0.0,
            'att_gas_used' => 5.5,
            'def_gas_used' => 0.0,
            'money_destroyed' => 0.0,
            'resistance_lost' => 10,
            'military_salvage_aluminum' => 0.0,
            'military_salvage_steel' => 0.0,
            'att_soldiers_used' => 5000,
            'att_soldiers_lost' => 120,
            'def_soldiers_used' => 3000,
            'def_soldiers_lost' => 340,
            'money_looted' => 1234567.89,
            'coal_looted' => 12.5,
            'infra_destroyed_percentage' => 3.65,
            'improvements_destroyed' => ['coal_mine'],
            'cities_infra_before' => [1250.75],
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    protected function updatedColumns(string $sql): array
    {
        preg_match('/\bset\b(.*)\bwhere\b/is', $sql, $matches);

        return array_map(
            fn (string $assignment): string => trim((string) preg_replace('/^.*\./', '', trim(explode('=', $assignment)[0])), '`"'),
            explode(',', $matches[1] ?? ''),
        );
    }
}
