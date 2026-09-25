<?php

namespace Tests\Unit\Raids;

use App\Support\Raids\RaidLootObservationMapper;
use Tests\TestCase;

class RaidLootObservationMapperTest extends TestCase
{
    public function test_victory_observation_maps_the_loser_alliance_and_looted_resources(): void
    {
        $row = RaidLootObservationMapper::map([
            'id' => 80,
            'war_id' => 70,
            'att_id' => 900,
            'def_id' => 555,
            'occurred_at' => '2026-09-10 12:00:00',
            'payload' => json_encode([
                'id' => 80, 'type' => 'VICTORY', 'victor' => 900, 'war_type' => 'raid',
                'original_attacker_id' => 900, 'original_defender_id' => 555,
                'att_alliance_id' => 11, 'def_alliance_id' => 22,
                'money_looted' => 1_000_000, 'coal_looted' => 12.5, 'food_looted' => '40',
                'loot_info' => 'looted 10% of their resources',
            ]),
        ]);

        $this->assertNotNull($row);
        $this->assertSame('victory', $row['kind']);
        $this->assertSame(900, $row['winner_nation_id']);
        $this->assertSame(555, $row['loser_nation_id']);
        $this->assertSame(22, $row['loser_alliance_id']);
        $this->assertSame('RAID', $row['war_type']);
        $this->assertSame(1_000_000.0, $row['money']);
        $this->assertSame(12.5, $row['coal']);
        $this->assertSame(40.0, $row['food']);
        $this->assertSame(0.0, $row['steel']);
        $this->assertSame('2026-09-10 12:00:00', $row['occurred_at']);
        $this->assertNull($row['loot_fraction']);
        $this->assertSame('pending', $row['fraction_source']);
    }

    public function test_alliance_loot_where_the_original_attacker_lost_uses_the_attacker_alliance(): void
    {
        $row = RaidLootObservationMapper::map([
            'id' => 81,
            'war_id' => 70,
            'att_id' => 555,
            'def_id' => 900,
            'occurred_at' => '2026-09-10 12:00:00',
            'payload' => [
                'type' => 'ALLIANCELOOT', 'victor' => 555,
                'original_attacker_id' => 900, 'original_defender_id' => 555,
                'att_alliance_id' => 11, 'def_alliance_id' => 0,
                'money_stolen' => 250,
            ],
        ]);

        $this->assertSame('alliance_loot', $row['kind']);
        $this->assertSame(555, $row['winner_nation_id']);
        $this->assertSame(900, $row['loser_nation_id']);
        $this->assertSame(11, $row['loser_alliance_id']);
        $this->assertSame(250.0, $row['money']);
    }

    public function test_ground_attacks_and_unreadable_payloads_are_ignored(): void
    {
        $this->assertNull(RaidLootObservationMapper::map([
            'id' => 82, 'war_id' => 70, 'att_id' => 1, 'def_id' => 2, 'occurred_at' => '2026-09-10 12:00:00',
            'payload' => ['type' => 'GROUND', 'money_stolen' => 100],
        ]));
        $this->assertNull(RaidLootObservationMapper::map([
            'id' => 83, 'war_id' => 70, 'att_id' => 1, 'def_id' => 2, 'occurred_at' => '2026-09-10 12:00:00',
            'payload' => '{not json',
        ]));
    }

    public function test_missing_fields_fall_back_to_attacker_victor_ordinary_war_and_zero_loot(): void
    {
        $row = RaidLootObservationMapper::map([
            'id' => 84, 'war_id' => 70, 'att_id' => 7, 'def_id' => 8, 'occurred_at' => null,
            'payload' => ['type' => 'victory', 'date' => '2026-09-11T01:02:03+00:00'],
        ]);

        $this->assertSame(7, $row['winner_nation_id']);
        $this->assertSame(8, $row['loser_nation_id']);
        $this->assertNull($row['loser_alliance_id']);
        $this->assertSame('ORDINARY', $row['war_type']);
        $this->assertSame(0.0, $row['money']);
        $this->assertSame('2026-09-11 01:02:03', $row['occurred_at']);
    }

    public function test_war_attack_rows_use_the_war_for_alliance_and_type(): void
    {
        $attack = [
            'id' => 90, 'war_id' => 71, 'att_id' => 900, 'def_id' => 555, 'date' => '2026-09-12 08:00:00',
            'type' => 'VICTORY', 'victor' => 900, 'money_looted' => '500.00', 'steel_looted' => '3.25',
        ];

        $row = RaidLootObservationMapper::map($attack + [
            'war' => ['def_id' => 555, 'war_type' => 'ATTRITION', 'att_alliance_id' => 11, 'def_alliance_id' => 33],
        ]);

        $this->assertSame(33, $row['loser_alliance_id']);
        $this->assertSame('ATTRITION', $row['war_type']);
        $this->assertSame(500.0, $row['money']);
        $this->assertSame(3.25, $row['steel']);

        $withoutWar = RaidLootObservationMapper::map($attack + ['war' => null]);

        $this->assertNull($withoutWar['loser_alliance_id']);
        $this->assertSame('ORDINARY', $withoutWar['war_type']);
    }
}
