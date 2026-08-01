<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\LeaderboardDirectoryService;
use App\Services\War\RaidLeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RaidLeaderboardAccessControlTest extends TestCase
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

    public function test_former_member_cannot_view_raid_leaderboard(): void
    {
        $user = $this->userWithNation(999, 'NOALLIANCE');
        $directoryService = Mockery::mock(LeaderboardDirectoryService::class);
        $directoryService->shouldNotReceive('getPageData');
        $this->app->instance(LeaderboardDirectoryService::class, $directoryService);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('leaderboards.index', ['board' => 'raid-performance']))
            ->assertForbidden();
    }

    public function test_current_member_can_view_raid_leaderboard(): void
    {
        $user = $this->userWithNation(777);
        $this->mockDirectoryForMember($user, 1);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->get(route('leaderboards.index', ['board' => 'raid-performance']))
            ->assertOk();
    }

    public function test_raid_leaderboard_rejects_ranges_over_ninety_days(): void
    {
        $user = $this->userWithNation(777);

        $this->actingAs($user)
            ->withoutMiddleware($this->optionalIdentityMiddleware())
            ->from(route('leaderboards.index', ['board' => 'raid-performance']))
            ->get(route('leaderboards.index', [
                'board' => 'raid-performance',
                'from' => '2026-01-01',
                'to' => '2026-05-01',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('from');
    }

    public function test_raid_leaderboard_is_rate_limited_per_user(): void
    {
        $user = $this->userWithNation(777);
        $this->mockDirectoryForMember($user, 10);
        $this->actingAs($user)->withoutMiddleware($this->optionalIdentityMiddleware());

        foreach (range(1, 10) as $attempt) {
            $this->get(route('leaderboards.index', ['board' => 'raid-performance']))->assertOk();
        }

        $this->get(route('leaderboards.index', ['board' => 'raid-performance']))
            ->assertTooManyRequests();
    }

    public function test_applicants_are_excluded_from_ranked_raid_roster(): void
    {
        $member = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'nation_name' => 'Eligible Member',
        ]);
        Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'APPLICANT',
            'nation_name' => 'Excluded Applicant',
        ]);

        $payload = app(RaidLeaderboardService::class)->buildLeaderboard(
            now()->subDays(7)->startOfDay(),
            now()->endOfDay(),
            $member->id
        );
        $rankedNames = collect($payload['leaderboards']['loot'])->pluck('nation_name');

        $this->assertTrue($rankedNames->contains('Eligible Member'));
        $this->assertFalse($rankedNames->contains('Excluded Applicant'));
    }

    private function mockDirectoryForMember(User $user, int $times): void
    {
        $directoryService = Mockery::mock(LeaderboardDirectoryService::class);
        $directoryService->shouldReceive('getPageData')
            ->times($times)
            ->with('raid-performance', null, null, $user->nation_id)
            ->andReturn($this->leaderboardViewData());
        $this->app->instance(LeaderboardDirectoryService::class, $directoryService);
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

    /**
     * @return array<string, mixed>
     */
    private function leaderboardViewData(): array
    {
        $activeBoard = [
            'slug' => 'raid-performance',
            'title' => 'Raid performance',
            'description' => 'Current member raid performance.',
            'eyebrow' => 'Defense',
            'name' => 'Raid Performance',
            'icon' => 'R',
            'partial' => null,
        ];

        return [
            'activeBoard' => $activeBoard,
            'activePayload' => null,
            'liveBoards' => [$activeBoard],
            'dashboardBoards' => [],
            'plannedBoards' => [],
        ];
    }
}
