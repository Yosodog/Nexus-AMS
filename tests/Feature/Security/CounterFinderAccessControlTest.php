<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CounterFinderAccessControlTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pw.alliance_id', 777);
        Cache::flush();
        app(AllianceMembershipService::class)->clear();
    }

    public function test_former_member_with_war_permission_cannot_view_counter_roster(): void
    {
        $user = $this->grantPermissions($this->userWithNation(999, 'NOALLIANCE'), ['view-wars']);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('defense.counters'))
            ->assertForbidden();
    }

    public function test_current_member_without_war_permission_cannot_view_counter_roster(): void
    {
        $user = $this->userWithNation(777);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('defense.counters'))
            ->assertForbidden();
    }

    public function test_authorized_current_member_can_view_roster_without_applicants(): void
    {
        $user = $this->grantPermissions($this->userWithNation(777, leaderName: 'Eligible Viewer'), ['view-wars']);
        $this->userWithNation(777, 'APPLICANT', 'Hidden Applicant');

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('defense.counters'))
            ->assertOk()
            ->assertSee('Eligible Viewer')
            ->assertDontSee('Hidden Applicant');
    }

    private function userWithNation(
        int $allianceId,
        string $position = 'MEMBER',
        ?string $leaderName = null
    ): User {
        $nation = Nation::factory()->create([
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
            ...($leaderName !== null ? ['leader_name' => $leaderName] : []),
        ]);
        NationMilitary::query()->create([
            'nation_id' => $nation->id,
            ...array_fill_keys([
                'soldiers',
                'tanks',
                'aircraft',
                'ships',
                'missiles',
                'nukes',
                'spies',
                'soldiers_today',
                'tanks_today',
                'aircraft_today',
                'ships_today',
                'missiles_today',
                'nukes_today',
                'spies_today',
                'soldier_casualties',
                'soldier_kills',
                'tank_casualties',
                'tank_kills',
                'aircraft_casualties',
                'aircraft_kills',
                'ship_casualties',
                'ship_kills',
                'missile_casualties',
                'missile_kills',
                'nuke_casualties',
                'nuke_kills',
                'spy_casualties',
                'spy_kills',
                'spy_attacks',
            ], 0),
        ]);

        return $this->createVerifiedUser(['nation_id' => $nation->id]);
    }

    /**
     * @return array<int, class-string>
     */
    private function optionalIdentityMiddleware(): array
    {
        return [
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ];
    }
}
