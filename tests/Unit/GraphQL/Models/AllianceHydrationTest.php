<?php

namespace Tests\Unit\GraphQL\Models;

use App\GraphQL\Models\Alliance;
use App\GraphQL\Models\BankRecord;
use App\GraphQL\Models\Nation;
use Tests\UnitTestCase;

class AllianceHydrationTest extends UnitTestCase
{
    public function test_build_with_json_hydrates_nested_nations_and_tax_records(): void
    {
        $alliance = new Alliance;

        $alliance->buildWithJSON((object) [
            'id' => '777',
            'name' => 'Test Alliance',
            'acronym' => 'TA',
            'score' => 12345.67,
            'color' => 'blue',
            'average_score' => null,
            'nations' => [
                [
                    'id' => '1001',
                    'nation_name' => 'Nested Nation',
                    'leader_name' => 'Nested Leader',
                    'alliance_position' => 'MEMBER',
                    'vacation_mode_turns' => 0,
                ],
            ],
            'taxrecs' => [
                [
                    'id' => 9001,
                    'date' => '2026-06-01T12:00:00+00:00',
                    'sender_id' => 1001,
                    'sender_type' => 1,
                    'receiver_id' => 777,
                    'receiver_type' => 2,
                    'banker_id' => 42,
                    'money' => 5000,
                    'tax_id' => 123,
                ],
            ],
            'accept_members' => true,
            'flag' => 'https://example.test/flag.png',
            'forum_link' => 'https://forum.example.test',
            'discord_link' => 'https://discord.example.test',
            'wiki_link' => null,
            'money' => 1000,
            'coal' => 1,
            'oil' => 2,
            'uranium' => 3,
            'iron' => 4,
            'bauxite' => 5,
            'lead' => 6,
            'gasoline' => 7,
            'munitions' => 8,
            'steel' => 9,
            'aluminum' => 10,
            'food' => 11,
            'rank' => 1,
        ]);

        $this->assertSame('777', $alliance->id);
        $this->assertSame(0.0, $alliance->average_score);
        $this->assertSame(1, $alliance->nations->count());
        $this->assertSame(1, $alliance->taxrecs->count());
        $this->assertInstanceOf(Nation::class, $alliance->nations->current());
        $this->assertSame(1001, $alliance->nations->current()->id);
        $this->assertInstanceOf(BankRecord::class, $alliance->taxrecs->current());
        $this->assertSame(0.0, $alliance->taxrecs->current()->coal);
        $this->assertSame(123, $alliance->taxrecs->current()->tax_id);
    }
}
