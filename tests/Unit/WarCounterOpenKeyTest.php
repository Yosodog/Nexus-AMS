<?php

namespace Tests\Unit;

use App\Models\Nation;
use App\Models\WarCounter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarCounterOpenKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_open_counter_can_exist_for_an_aggressor(): void
    {
        $aggressor = Nation::factory()->create();

        $first = $this->createCounter($aggressor, 'draft');

        $this->assertSame(WarCounter::ACTIVE_KEY_VALUE, $first->fresh()->active_key);
        $this->expectException(UniqueConstraintViolationException::class);

        $this->createCounter($aggressor, 'active');
    }

    public function test_archiving_a_counter_releases_the_open_counter_key(): void
    {
        $aggressor = Nation::factory()->create();
        $first = $this->createCounter($aggressor, 'draft');

        $first->update(['status' => 'archived']);

        $this->assertNull($first->fresh()->active_key);

        $second = $this->createCounter($aggressor, 'draft');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(WarCounter::ACTIVE_KEY_VALUE, $second->fresh()->active_key);
    }

    private function createCounter(Nation $aggressor, string $status): WarCounter
    {
        return WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
            'status' => $status,
        ]);
    }
}
