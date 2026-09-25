<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
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

    public function test_admin_assessment_lists_recent_predictions(): void
    {
        $member = Nation::factory()->create();
        $target = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $member->id]);
        RaidPrediction::query()->create([
            'war_id' => 123,
            'attacker_nation_id' => $member->id,
            'target_nation_id' => $target->id,
            'declared_at' => now(),
            'captured_at' => now(),
            'expected_net' => 12345,
            'expected_net_low' => 9000,
            'expected_net_high' => 20000,
            'finder_rank' => 3,
            'actual_net' => 15000,
        ]);
        Gate::define('view-diagnostic-info', fn (): bool => true);

        $this->withoutMiddleware([AdminMiddleware::class, DiscordVerifiedMiddleware::class, EnsureUserIsVerified::class, EnsureMfaConfigured::class])
            ->actingAs($user)
            ->get(route('admin.raid-assessment'))
            ->assertOk()
            ->assertSee('War #123')
            ->assertSee('$12,345')
            ->assertSee('$9,000 – $20,000')
            ->assertSee('#3')
            ->assertSee('Ranking quality')
            ->assertSee('Stockpile estimator (world)')
            ->assertDontSee('Evaluation failures');
    }

    public function test_member_results_show_the_expected_range_confidence_and_finder_rank(): void
    {
        $own = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $own->id]);
        RaidPrediction::query()->create([
            'war_id' => 321, 'attacker_nation_id' => $own->id, 'target_nation_id' => Nation::factory()->create()->id,
            'declared_at' => now(), 'captured_at' => now(), 'capture_status' => 'ready',
            'expected_net' => 5000, 'expected_net_low' => 2500, 'expected_net_high' => 8000,
            'confidence' => 'medium', 'finder_rank' => 2,
        ]);

        $this->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureUserIsVerified::class, EnsureMfaConfigured::class])
            ->actingAs($user)->get(route('defense.raid-results'))
            ->assertOk()
            ->assertSee('$5,000')
            ->assertSee('$2,500 – $8,000')
            ->assertSee('Medium')
            ->assertSee('#2')
            ->assertSee('Victory calibration');
    }
}
