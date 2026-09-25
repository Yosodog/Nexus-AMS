<?php

namespace Tests\Feature\Raids;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Models\Nation;
use App\Models\RaidTargetClaim;
use App\Models\RaidTargetProfile;
use App\Models\User;
use App\Services\Admin\PendingRequestRecoveryService;
use App\Services\Raids\RaidTargetClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RaidTargetClaimTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private User $member;

    private User $otherMember;

    private RaidTargetProfile $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        Cache::forever('alliances:membership:ids', [777]);
        $this->member = User::factory()->verified()->create(['nation_id' => Nation::factory()->create(['alliance_id' => 777])->id]);
        $this->otherMember = User::factory()->verified()->create(['nation_id' => Nation::factory()->create(['alliance_id' => 777])->id]);
        $this->target = RaidTargetProfile::factory()->create();
    }

    public function test_member_claims_a_target_for_the_configured_window(): void
    {
        $this->claim($this->member)
            ->assertCreated()
            ->assertJsonPath('data.target_nation_id', $this->target->nation_id)
            ->assertJsonPath('data.nation_id', $this->member->nation_id)
            ->assertJsonPath('data.mine', true);

        $claim = RaidTargetClaim::query()->firstOrFail();
        $this->assertSame(RaidTargetClaim::STATUS_ACTIVE, $claim->status);
        $this->assertSame(1, $claim->pending_key);
        $this->assertTrue($claim->expires_at->equalTo(now()->addMinutes(120)));
    }

    public function test_a_second_member_cannot_claim_an_actively_claimed_target(): void
    {
        $this->claim($this->member)->assertCreated();

        $this->claim($this->otherMember)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_nation_id' => 'Another member already claimed this target.']);

        $this->assertSame(1, RaidTargetClaim::query()->count());
    }

    public function test_reclaiming_your_own_target_returns_the_existing_claim(): void
    {
        $first = $this->claim($this->member)->json('data.id');

        $this->claim($this->member)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame(1, RaidTargetClaim::query()->count());
    }

    public function test_targets_outside_the_finder_and_non_members_are_rejected(): void
    {
        $this->claim($this->member, 123_456_789)->assertUnprocessable()->assertJsonValidationErrors('target_nation_id');

        $outsider = User::factory()->verified()->create(['nation_id' => Nation::factory()->create()->id]);
        $this->claim($outsider)->assertForbidden();
    }

    public function test_the_claimer_can_release_a_claim(): void
    {
        $claimId = $this->claim($this->member)->json('data.id');

        $this->release($this->member, $claimId)->assertNoContent();

        $claim = RaidTargetClaim::query()->findOrFail($claimId);
        $this->assertSame(RaidTargetClaim::STATUS_RELEASED, $claim->status);
        $this->assertNull($claim->pending_key);
        $this->assertNotNull($claim->released_at);
        $this->claim($this->otherMember)->assertCreated();
    }

    public function test_other_members_cannot_release_a_claim_without_view_raids(): void
    {
        $claimId = $this->claim($this->member)->json('data.id');

        $this->release($this->otherMember, $claimId)->assertForbidden();
        $this->assertSame(RaidTargetClaim::STATUS_ACTIVE, RaidTargetClaim::query()->findOrFail($claimId)->status);

        $this->otherMember = $this->grantPermissions($this->otherMember->fresh(), ['view-raids']);
        $this->release($this->otherMember, $claimId)->assertNoContent();
    }

    public function test_expired_claims_are_closed_by_the_command_and_on_the_next_claim(): void
    {
        $claimId = $this->claim($this->member)->json('data.id');
        $this->travel(121)->minutes();

        $this->artisan('raids:expire-claims')->assertSuccessful();

        $claim = RaidTargetClaim::query()->findOrFail($claimId);
        $this->assertSame(RaidTargetClaim::STATUS_EXPIRED, $claim->status);
        $this->assertNull($claim->pending_key);

        RaidTargetClaim::factory()->expired()->create(['target_nation_id' => $this->target->nation_id, 'nation_id' => $this->member->nation_id]);
        $this->claim($this->otherMember)->assertCreated();
        $this->assertSame(2, RaidTargetClaim::query()->where('status', RaidTargetClaim::STATUS_EXPIRED)->count());
    }

    public function test_declaring_war_marks_the_attackers_claim_declared(): void
    {
        $claimId = $this->claim($this->member)->json('data.id');

        app(RaidTargetClaimService::class)->markDeclared((int) $this->otherMember->nation_id, $this->target->nation_id);
        $this->assertSame(RaidTargetClaim::STATUS_ACTIVE, RaidTargetClaim::query()->findOrFail($claimId)->status);

        app(RaidTargetClaimService::class)->markDeclared((int) $this->member->nation_id, $this->target->nation_id);

        $claim = RaidTargetClaim::query()->findOrFail($claimId);
        $this->assertSame(RaidTargetClaim::STATUS_DECLARED, $claim->status);
        $this->assertNull($claim->pending_key);
    }

    public function test_stuck_claims_are_listed_and_released_by_pending_recovery(): void
    {
        RaidTargetClaim::factory()->create(['target_nation_id' => $this->target->nation_id, 'created_at' => now()->subDays(3)]);
        $recovery = app(PendingRequestRecoveryService::class);

        $summary = collect($recovery->summaries())->firstWhere('type', 'raid_target_claims');
        $this->assertSame(1, $summary['stalePending']);

        $this->assertSame(1, $recovery->release('raid_target_claims', 24)['releasedCount']);
        $claim = RaidTargetClaim::query()->firstOrFail();
        $this->assertSame(RaidTargetClaim::STATUS_RELEASED, $claim->status);
        $this->assertNull($claim->pending_key);
        $this->assertNotNull($claim->released_at);
    }

    private function claim(User $user, ?int $targetId = null): TestResponse
    {
        return $this->actingAs($user)
            ->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->postJson(route('api.raid-finder.claims.store'), ['target_nation_id' => $targetId ?? $this->target->nation_id]);
    }

    private function release(User $user, int $claimId): TestResponse
    {
        return $this->actingAs($user)
            ->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->deleteJson(route('api.raid-finder.claims.destroy', $claimId));
    }
}
