<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\RaidPrediction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RaidReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_only_see_their_own_predictions_even_with_another_nation_parameter(): void
    {
        $own = Nation::factory()->create();
        $other = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $own->id]);
        foreach ([$own, $other] as $index => $nation) {
            RaidPrediction::query()->create([
                'war_id' => 100 + $index, 'attacker_nation_id' => $nation->id, 'target_nation_id' => $other->id,
                'declared_at' => now(), 'captured_at' => now(), 'capture_status' => 'incomplete',
                'capture_reason' => $index === 0 ? 'Own capture evidence' : 'Other member private capture',
            ]);
        }
        $this->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureUserIsVerified::class, EnsureMfaConfigured::class])
            ->actingAs($user)->get(route('defense.raid-results', ['nation_id' => $other->id]))
            ->assertOk()->assertViewHas('predictions', fn ($rows): bool => $rows->total() === 1 && $rows->first()->attacker_nation_id === $own->id);
    }

    public function test_aggregate_assessment_requires_diagnostic_permission(): void
    {
        $user = User::factory()->verified()->create(['nation_id' => Nation::factory()->create()->id]);
        Gate::define('view-diagnostic-info', fn (): bool => false);
        $this->actingAs($user)->get(route('admin.raid-assessment'))->assertForbidden();
    }
}
