<?php

namespace Tests\Unit\GraphQL\Models;

use App\GraphQL\Models\Nation;
use App\Services\GraphQLQueryBuilder;
use App\Services\SelectionSetHelper;
use PHPUnit\Framework\Attributes\Test;
use Tests\UnitTestCase;

class MilitaryResearchHydrationTest extends UnitTestCase
{
    #[Test]
    public function it_hydrates_and_tracks_nested_research_fields(): void
    {
        $nation = new Nation;
        $nation->buildWithJSON((object) [
            'id' => 99,
            'military_research' => (object) [
                'ground_capacity' => 7,
                'air_cost' => 25,
            ],
            'treasures' => [[
                'name' => 'Test Treasure',
                'bonus' => 2,
                'nation_id' => 99,
            ]],
        ]);

        $this->assertSame(7, $nation->ground_capacity_research);
        $this->assertSame(20, $nation->air_cost_research);
        $this->assertNull($nation->ground_cost_research);
        $this->assertTrue($nation->hasSourceField('ground_capacity_research'));
        $this->assertFalse($nation->hasSourceField('ground_cost_research'));
        $this->assertSame('Test Treasure', $nation->treasures[0]->name);
        $this->assertSame(2, $nation->treasures[0]->bonus);
    }

    #[Test]
    public function nation_selection_renders_research_as_a_nested_object(): void
    {
        $builder = (new GraphQLQueryBuilder)->setRootField('nation');
        SelectionSetHelper::applyNationSelection($builder);
        $query = $builder->build();

        $this->assertStringContainsString(
            'military_research { ground_capacity ground_cost air_capacity air_cost naval_capacity naval_cost }',
            $query
        );
        $this->assertStringContainsString('treasures { name bonus nation_id }', $query);
    }

    #[Test]
    public function unexpected_research_keys_are_ignored(): void
    {
        $nation = new Nation;
        $nation->buildWithJSON((object) [
            'id' => 99,
            'military_research' => (object) [
                'sourceFields' => [],
                'ground_cost' => 4,
            ],
        ]);

        $this->assertSame(4, $nation->ground_cost_research);
        $this->assertFalse($nation->hasSourceField('sourceFields'));
    }
}
