<?php

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\Discord\DiscordActorContextService;
use App\Services\Discord\DiscordMemberSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DiscordMemberSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD_ID = '123456789012345678';

    private DiscordActorContextService $contexts;

    private DiscordMemberSummaryService $summaries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contexts = app(DiscordActorContextService::class);
        $this->summaries = app(DiscordMemberSummaryService::class);
    }

    public function test_non_ready_contexts_remain_minimal_and_safe(): void
    {
        $summary = $this->summaries->summarize(
            $this->contexts->resolve(self::DISCORD_ID),
            1,
            $this->profileSync(),
        );

        $this->assertSame('unlinked', $summary['state']);
        $this->assertSame(1, $summary['contract_version']);
        $this->assertArrayNotHasKey('identity', $summary);
        $this->assertArrayNotHasKey('capabilities', $summary);
        $this->assertArrayNotHasKey('open_work', $summary);
    }

    public function test_ready_summary_exposes_only_compact_identity_membership_work_and_freshness(): void
    {
        $nation = Nation::factory()->create([
            'nation_name' => 'Example Nation',
            'leader_name' => 'Example Leader',
        ]);
        $actor = User::factory()->create([
            'name' => 'Example Member',
            'nation_id' => $nation->id,
            'verified_at' => now(),
        ]);
        DiscordAccount::factory()->for($actor)->create([
            'discord_id' => self::DISCORD_ID,
            'discord_username' => 'example.member',
            'linked_at' => now()->subDay(),
            'unlinked_at' => null,
        ]);
        Application::query()->create([
            'nation_id' => $nation->id,
            'leader_name_snapshot' => $nation->leader_name,
            'discord_user_id' => self::DISCORD_ID,
            'discord_username' => 'example.member',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);

        $summary = $this->summaries->summarize(
            $this->contexts->resolve(self::DISCORD_ID),
            7,
            $this->profileSync(),
        );

        $this->assertSame('ready', $summary['state']);
        $this->assertSame('Example Member', $summary['identity']['display_name']);
        $this->assertSame('Example Nation', $summary['nation']['name']);
        $this->assertSame($nation->alliance_id, $summary['alliance']['id']);
        $this->assertSame(7, $summary['capabilities']['revision']);
        $this->assertSame('member.self', $summary['capabilities']['items'][0]['key']);
        $this->assertSame(1, $summary['open_work']['by_type']['applications_own']);
        $this->assertSame('healthy', $summary['profile_sync']['state']);
        $this->assertContains($summary['freshness']['state'], ['fresh', 'stale']);
        $this->assertSame([
            'profile' => '/user/settings',
            'application' => '/apply',
            'audit' => '/audit',
        ], $summary['links']);
        $this->assertArrayNotHasKey('accounts', $summary);
        $this->assertArrayNotHasKey('balances', $summary);
        $this->assertArrayNotHasKey('military', $summary);
    }

    public function test_missing_local_nation_degrades_to_the_safe_no_nation_state(): void
    {
        $actor = User::factory()->create([
            'nation_id' => 999999,
            'verified_at' => now(),
        ]);
        DiscordAccount::factory()->for($actor)->create([
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);

        $summary = $this->summaries->summarize(
            $this->contexts->resolve(self::DISCORD_ID),
            1,
            $this->profileSync(),
        );

        $this->assertSame('no_nation', $summary['state']);
        $this->assertArrayNotHasKey('identity', $summary);
    }

    public function test_ready_summary_requires_a_complete_profile_snapshot_and_revision(): void
    {
        $nation = Nation::factory()->create();
        $actor = User::factory()->create(['nation_id' => $nation->id, 'verified_at' => now()]);
        DiscordAccount::factory()->for($actor)->create([
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
        $context = $this->contexts->resolve(self::DISCORD_ID);

        $this->expectException(InvalidArgumentException::class);
        $this->summaries->summarize($context, 0, ['state' => 'unknown']);
    }

    /** @return array{state: string, label: string, checked_at: string, issues: list<string>} */
    private function profileSync(): array
    {
        return [
            'state' => 'healthy',
            'label' => 'Profile is synchronized',
            'checked_at' => now()->toIso8601String(),
            'issues' => [],
        ];
    }
}
