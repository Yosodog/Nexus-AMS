<?php

namespace Tests\Feature;

use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use App\Services\QueryService;
use App\Services\RaidIntelligenceRefreshService;
use App\Services\RaidIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_selection_never_requests_private_resources_or_identity(): void
    {
        $fields = RaidIntelligenceRefreshService::publicFields();
        foreach (['money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline', 'munitions', 'steel', 'aluminum', 'food', 'credits', 'discord', 'discord_id', 'tax_id', 'spies'] as $private) {
            $this->assertNotContains($private, $fields);
        }
        $this->assertContains('last_active', $fields);
        $this->assertContains('soldiers', $fields);
    }

    public function test_capture_chooses_only_clean_snapshot_even_when_raw_attack_arrived_first(): void
    {
        $cutoff = CarbonImmutable::parse('2026-09-13 12:00:00');
        $old = RaidNationObservation::factory()->create([
            'nation_id' => 1, 'observed_at' => $cutoff->subMinutes(5), 'payload' => ['id' => 1, 'soldiers' => 1000],
        ]);
        RaidNationObservation::factory()->create([
            'nation_id' => 1, 'observed_at' => $cutoff->subMinute(), 'payload' => ['id' => 1, 'soldiers' => 100],
        ]);
        RaidAttackObservation::factory()->create([
            'war_id' => 42, 'occurred_at' => $cutoff->subMinutes(2), 'observed_at' => $cutoff->subMinutes(2),
        ]);
        $frozen = app(RaidIntelligenceService::class)->nationAt(1, $cutoff, 42);
        $this->assertSame($old->id, $frozen['observation_id']);
        $this->assertSame(1000, $frozen['soldiers']);
    }

    public function test_capture_does_not_use_state_observed_at_the_declaration_cutoff(): void
    {
        $cutoff = CarbonImmutable::parse('2026-09-13 12:00:00');
        RaidNationObservation::factory()->create([
            'nation_id' => 77,
            'observed_at' => $cutoff->subMinute(),
            'payload' => ['id' => 77, 'soldiers' => 1000],
        ]);
        RaidNationObservation::factory()->create([
            'nation_id' => 77,
            'observed_at' => $cutoff,
            'payload' => ['id' => 77, 'soldiers' => 100],
        ]);

        $frozen = app(RaidIntelligenceService::class)->nationAt(77, $cutoff, 999);

        $this->assertSame(1000, $frozen['soldiers']);
    }

    public function test_capture_rejects_derived_provenance_and_future_observations(): void
    {
        $cutoff = CarbonImmutable::parse('2026-09-13 12:00:00');
        RaidNationObservation::factory()->create(['nation_id' => 1, 'observed_at' => $cutoff->subMinute(), 'provenance_war_ids' => [42]]);
        RaidNationObservation::factory()->create(['nation_id' => 1, 'observed_at' => $cutoff->addMinute()]);
        $this->assertSame([], app(RaidIntelligenceService::class)->nationAt(1, $cutoff, 42));
        $frozen = app(RaidIntelligenceService::class)->freeze(1, 2, $cutoff, 42);
        $this->assertSame('incomplete', $frozen['status']);
        $this->assertSame([], $frozen['prices']);
    }

    public function test_refresh_keeps_outside_attackers_and_strips_private_response_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 02:16:16', 'UTC'));
        config(['raids.history_pages' => 2, 'raids.history_page_size' => 1]);
        $queries = $this->mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($builder, ...$args): bool => $builder->getRootField() === 'wars'
                && str_contains($builder->build(), 'page: 1')
                && str_contains($builder->build(), 'after: "2026-08-14 02:16:16"'))
            ->andReturn((object) [(object) [
                'id' => 55, 'att_id' => 800, 'def_id' => 900, 'war_type' => 'RAID', 'turns_left' => 0,
                'attacks' => [(object) ['id' => 123, 'att_id' => 800, 'def_id' => 900, 'date' => now()->subDay()->toIso8601String(), 'type' => 'VICTORY', 'money_looted' => 100000]],
            ]]);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($builder, ...$args): bool => $builder->getRootField() === 'wars' && str_contains($builder->build(), 'page: 2'))
            ->andReturn((object) []);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($builder, ...$args): bool => $builder->getRootField() === 'nations')
            ->andReturn((object) [(object) ['id' => 900, 'score' => 1000, 'num_cities' => 0, 'cities' => [], 'money' => 999999999, 'discord_id' => 'private']]);
        app(RaidIntelligenceRefreshService::class)->refresh([900]);
        $attack = RaidAttackObservation::query()->findOrFail(123);
        $this->assertSame(55, $attack->war_id);
        $snapshot = RaidNationObservation::query()->where('nation_id', 900)->firstOrFail();
        $this->assertArrayNotHasKey('money', $snapshot->payload);
        $this->assertArrayNotHasKey('discord_id', $snapshot->payload);
        $this->assertArrayNotHasKey('cities', $snapshot->payload);
        $this->assertSame(1, $snapshot->current_key);
        $this->assertContains(55, $snapshot->provenance_war_ids);
        $this->assertTrue($snapshot->payload['history_complete']);
    }

    public function test_refresh_does_not_rewrite_unchanged_historical_attacks(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
        config(['raids.history_pages' => 1, 'raids.history_page_size' => 10]);
        $attackDate = now()->subDay()->toIso8601String();
        $storedAt = now()->subHours(2);
        $payload = [
            'id' => 123,
            'att_id' => 800,
            'def_id' => 900,
            'date' => $attackDate,
            'type' => 'VICTORY',
            'money_looted' => 100000,
            'war_id' => 55,
            'war_type' => 'RAID',
            'original_attacker_id' => 800,
            'original_defender_id' => 900,
            'att_alliance_id' => 0,
            'def_alliance_id' => 0,
        ];
        RaidAttackObservation::query()->create([
            'id' => 123,
            'war_id' => 55,
            'att_id' => 800,
            'def_id' => 900,
            'occurred_at' => CarbonImmutable::parse($attackDate),
            'observed_at' => $storedAt,
            'payload' => $payload,
            'created_at' => $storedAt,
            'updated_at' => $storedAt,
        ]);
        $queries = $this->mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($builder, ...$args): bool => $builder->getRootField() === 'wars')
            ->andReturn((object) [(object) [
                'id' => 55,
                'att_id' => 800,
                'def_id' => 900,
                'war_type' => 'RAID',
                'turns_left' => 0,
                'attacks' => [(object) array_slice($payload, 0, 6, preserve_keys: true)],
            ]]);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($builder, ...$args): bool => $builder->getRootField() === 'nations')
            ->andReturn((object) [(object) ['id' => 900, 'score' => 1000, 'num_cities' => 0, 'cities' => []]]);

        app(RaidIntelligenceRefreshService::class)->refresh([900]);

        $attack = RaidAttackObservation::query()->findOrFail(123);
        $this->assertTrue($storedAt->equalTo($attack->observed_at));
        $this->assertTrue($storedAt->equalTo($attack->updated_at));
    }

    public function test_selected_target_recheck_fetches_current_nations_and_wars_without_history_or_public_writes(): void
    {
        $queries = $this->mock(QueryService::class);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($query, ...$args): bool => $query->getRootField() === 'nations' && ! str_contains($query->build(), 'cities'))
            ->andReturn((object) [(object) ['id' => 1, 'score' => 1000], (object) ['id' => 2, 'score' => 1000]]);
        $queries->shouldReceive('sendQuery')->once()->ordered()
            ->withArgs(fn ($query, ...$args): bool => $query->getRootField() === 'wars' && str_contains($query->build(), 'active: true') && ! str_contains($query->build(), 'attacks'))
            ->andReturn((object) [(object) ['id' => 40, 'att_id' => 1, 'def_id' => 2]]);
        $result = app(RaidIntelligenceRefreshService::class)->currentAvailability([1, 2]);
        $this->assertSame(40, $result[2]['active_wars'][0]['id']);
        $this->assertNotEmpty($result[1]['observed_at']);
        $this->assertDatabaseCount('raid_nation_observations', 0);
        $this->assertDatabaseCount('raid_attack_observations', 0);
    }
}
