<?php

namespace Tests\Feature\Console;

use App\Models\RaidAttackObservation;
use App\Models\RaidNationObservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneRaidIntelligenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prunes_expired_history_while_retaining_each_nations_latest_observation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
        config([
            'raids.checkpoint_retention_days' => 31,
            'raids.history_days' => 31,
            'raids.attack_retention_days' => 31,
            'raids.prune_batch_size' => 2,
        ]);

        $expired = RaidNationObservation::factory()->create([
            'nation_id' => 1,
            'observed_at' => now()->subDays(32),
            'confirmed_through' => now()->subDays(32),
        ]);
        $latest = RaidNationObservation::factory()->create([
            'nation_id' => 1,
            'observed_at' => now()->subHour(),
            'current_key' => 1,
        ]);
        $onlyObservation = RaidNationObservation::factory()->create([
            'nation_id' => 2,
            'observed_at' => now()->subDays(40),
            'confirmed_through' => now()->subDays(40),
            'current_key' => 1,
        ]);
        $expiredAttack = RaidAttackObservation::factory()->create([
            'occurred_at' => now()->subDays(32),
        ]);
        $retainedAttack = RaidAttackObservation::factory()->create([
            'occurred_at' => now()->subDays(30),
        ]);

        $this->artisan('raids:prune-intelligence')
            ->expectsOutputToContain('2 raid intelligence observations pruned.')
            ->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($latest);
        $this->assertModelExists($onlyObservation);
        $this->assertModelMissing($expiredAttack);
        $this->assertModelExists($retainedAttack);
    }

    public function test_pretend_reports_matches_without_deleting_them(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
        config(['raids.checkpoint_retention_days' => 31]);

        $expired = RaidNationObservation::factory()->create([
            'nation_id' => 1,
            'observed_at' => now()->subDays(32),
            'confirmed_through' => now()->subDays(32),
        ]);
        RaidNationObservation::factory()->create([
            'nation_id' => 1,
            'observed_at' => now(),
            'current_key' => 1,
        ]);

        $this->artisan('raids:prune-intelligence', ['--pretend' => true])
            ->expectsOutputToContain('1 raid intelligence observations would prune.')
            ->assertSuccessful();

        $this->assertModelExists($expired);
    }
}
