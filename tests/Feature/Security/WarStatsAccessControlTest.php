<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class WarStatsAccessControlTest extends TestCase
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

    public function test_former_member_and_applicant_cannot_view_war_stats(): void
    {
        $target = Nation::factory()->create(['alliance_id' => 999]);

        foreach ([
            $this->userWithNation(999, 'NOALLIANCE'),
            $this->userWithNation(777, 'APPLICANT'),
        ] as $user) {
            $this->actingAs($user)
                ->withoutMiddleware($this->optionalIdentityMiddleware())
                ->get(route('defense.war-stats', ['nation_id' => $target->id]))
                ->assertForbidden();
        }
    }

    public function test_current_member_can_inspect_any_nations_war_stats(): void
    {
        $user = $this->userWithNation(777);
        $externalNation = Nation::factory()->create([
            'alliance_id' => 999,
            'leader_name' => 'External Target',
        ]);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('defense.war-stats', ['nation_id' => $externalNation->id]))
            ->assertOk()
            ->assertSee('War Stats for External Target');
    }

    private function userWithNation(int $allianceId, string $position = 'MEMBER'): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
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
