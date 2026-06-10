<?php

namespace Tests\Unit\GraphQL\Models;

use App\GraphQL\Models\Attack;
use App\GraphQL\Models\City;
use App\GraphQL\Models\Nation;
use App\GraphQL\Models\War;
use Tests\UnitTestCase;

class NationHydrationTest extends UnitTestCase
{
    public function test_build_with_json_casts_scalars_and_hydrates_nested_collections(): void
    {
        $nation = new Nation;

        $nation->buildWithJSON((object) [
            'id' => '1234',
            'alliance_id' => '777',
            'alliance_position' => 'APPLICANT',
            'nation_name' => 'Hydrated Nation',
            'leader_name' => 'Hydrated Leader',
            'continent' => 'north_america',
            'color' => 'beige',
            'num_cities' => '1',
            'score' => '4567.89',
            'vacation_mode_turns' => 99999,
            'beige_turns' => '3',
            'soldiers' => '50000',
            'tanks' => '2500',
            'aircraft' => '900',
            'ships' => '45',
            'money' => '123456.78',
            'projects' => '42',
            'last_active' => '2026-06-01T15:30:00+00:00',
            'cities' => [$this->cityPayload()],
            'wars' => [$this->warPayload()],
        ]);

        $this->assertSame(1234, $nation->id);
        $this->assertSame(777, $nation->alliance_id);
        $this->assertTrue($nation->isApplicant());
        $this->assertSame(65000, $nation->vacation_mode_turns);
        $this->assertSame(0, $nation->soldiers_today);
        $this->assertSame(0, $nation->aircraft_today);
        $this->assertSame('2026-06-01 15:30:00', $nation->last_active);
        $this->assertSame(1, $nation->cities->count());
        $this->assertInstanceOf(City::class, $nation->cities->current());
        $this->assertSame('2026-03-01', $nation->cities->current()->nuke_date);
        $this->assertSame(1, $nation->wars->count());
        $this->assertInstanceOf(War::class, $nation->wars->current());
        $this->assertInstanceOf(Attack::class, $nation->wars->current()->attacks[0]);
        $this->assertSame(250, $nation->wars->current()->attacks[0]->money_looted);
    }

    /**
     * @return array<string, mixed>
     */
    private function cityPayload(): array
    {
        return [
            'id' => '10',
            'nation_id' => '1234',
            'name' => 'Capital',
            'date' => '2026-01-01',
            'nuke_date' => '2026-03-01T00:00:00+00:00',
            'infrastructure' => 2000.5,
            'land' => 1500.0,
            'powered' => true,
            'oil_power' => 0,
            'wind_power' => 0,
            'coal_power' => 1,
            'nuclear_power' => 0,
            'coal_mine' => 0,
            'oil_well' => 0,
            'uranium_mine' => 0,
            'barracks' => 5,
            'farm' => 0,
            'police_station' => 3,
            'hospital' => 3,
            'recycling_center' => 3,
            'subway' => 1,
            'supermarket' => 4,
            'bank' => 5,
            'shopping_mall' => 4,
            'stadium' => 2,
            'lead_mine' => 0,
            'iron_mine' => 0,
            'bauxite_mine' => 0,
            'oil_refinery' => 0,
            'aluminum_refinery' => 0,
            'steel_mill' => 0,
            'munitions_factory' => 0,
            'factory' => 5,
            'hangar' => 5,
            'drydock' => 3,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function warPayload(): array
    {
        return [
            'id' => 55,
            'reason' => 'raid',
            'att_id' => 1234,
            'def_id' => 4321,
            'attacks' => [
                [
                    'money_looted' => 250.0,
                    'coal_looted' => 2.0,
                ],
            ],
        ];
    }
}
