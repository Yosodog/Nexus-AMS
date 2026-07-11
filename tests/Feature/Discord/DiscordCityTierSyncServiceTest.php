<?php

namespace Tests\Feature\Discord;

use App\Enums\DiscordQueueStatus;
use App\Jobs\SyncDiscordCityTierRolesJob;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\Offshore;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\Discord\DiscordCityTierSyncService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DiscordCityTierSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Alliance $primaryAlliance;

    private Alliance $offshoreAlliance;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-10 12:15:00');
        $this->primaryAlliance = Alliance::factory()->create();
        $this->offshoreAlliance = Alliance::factory()->create();
        config()->set('services.pw.alliance_id', $this->primaryAlliance->id);

        Offshore::query()->create([
            'name' => 'Enabled Offshore',
            'alliance_id' => $this->offshoreAlliance->id,
            'enabled' => true,
            'priority' => 1,
        ]);

        app(AllianceMembershipService::class)->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_snapshot_includes_enabled_offshores_fills_sparse_buckets_and_excludes_ineligible_users(): void
    {
        $primaryMember = $this->createLinkedMember($this->primaryAlliance, 7, '223456789012345671');
        $offshoreMember = $this->createLinkedMember($this->offshoreAlliance, 24, '223456789012345672');
        $this->createLinkedMember($this->primaryAlliance, 50, '223456789012345673', 'APPLICANT');
        $this->createLinkedMember(Alliance::factory()->create(), 60, '223456789012345674');
        $this->createLinkedMember($this->primaryAlliance, 40, '223456789012345675', disabled: true);
        $this->createLinkedMember($this->primaryAlliance, 45, '223456789012345676', unlinked: true);
        $this->createLinkedMember($this->primaryAlliance, 55, 'invalid-discord-id');

        $command = app(DiscordCityTierSyncService::class)->queueSnapshot();

        $this->assertSame(DiscordCityTierSyncService::ACTION, $command->action);
        $this->assertSame(10, $command->payload['bucket_size']);
        $this->assertSame([
            ['bucket_start' => 1, 'bucket_end' => 10, 'name' => 'Cities 1-10', 'discord_role_id' => null],
            ['bucket_start' => 11, 'bucket_end' => 20, 'name' => 'Cities 11-20', 'discord_role_id' => null],
            ['bucket_start' => 21, 'bucket_end' => 30, 'name' => 'Cities 21-30', 'discord_role_id' => null],
        ], $command->payload['roles']);
        $this->assertSame([
            ['discord_id' => '223456789012345671', 'bucket_start' => 1],
            ['discord_id' => '223456789012345672', 'bucket_start' => 21],
        ], $command->payload['members']);
        $this->assertSame([], $command->payload['managed_role_ids']);
        $this->assertDatabaseCount('discord_city_tier_roles', 3);
        $this->assertSame($primaryMember->nation_id, $primaryMember->nation?->id);
        $this->assertSame($offshoreMember->nation_id, $offshoreMember->nation?->id);

        $duplicate = app(DiscordCityTierSyncService::class)->queueSnapshot();
        $this->assertSame($command->id, $duplicate->id);
        $this->assertDatabaseCount('discord_queue', 1);
    }

    public function test_completed_results_are_persisted_and_empty_legacy_roles_are_retained_after_bucket_size_changes(): void
    {
        $this->createLinkedMember($this->primaryAlliance, 24, '223456789012345671');
        $service = app(DiscordCityTierSyncService::class);
        $firstCommand = $service->queueSnapshot();
        $firstCommand->forceFill([
            'status' => DiscordQueueStatus::Complete,
            'completed_at' => now(),
            'result' => [
                'roles' => [
                    ['bucket_start' => 1, 'bucket_end' => 10, 'discord_role_id' => '123456789012345671'],
                    ['bucket_start' => 11, 'bucket_end' => 20, 'discord_role_id' => '123456789012345672'],
                    ['bucket_start' => 21, 'bucket_end' => 30, 'discord_role_id' => '123456789012345673'],
                ],
            ],
        ])->save();

        SettingService::setDiscordCityTierBucketSize(20);
        Carbon::setTestNow(now()->addHour());
        $secondCommand = $service->queueSnapshot();

        $this->assertSame(20, SettingService::getDiscordCityTierBucketSize());
        $this->assertSame([
            ['bucket_start' => 1, 'bucket_end' => 20, 'name' => 'Cities 1-20', 'discord_role_id' => null],
            ['bucket_start' => 21, 'bucket_end' => 40, 'name' => 'Cities 21-40', 'discord_role_id' => null],
        ], $secondCommand->payload['roles']);
        $this->assertSame([
            '123456789012345671',
            '123456789012345672',
            '123456789012345673',
        ], $secondCommand->payload['managed_role_ids']);
        $this->assertDatabaseCount('discord_city_tier_roles', 5);
        $this->assertDatabaseHas('discord_city_tier_roles', [
            'bucket_start' => 11,
            'bucket_end' => 20,
            'discord_role_id' => '123456789012345672',
            'last_synced_queue_id' => $firstCommand->id,
        ]);
    }

    public function test_console_command_dispatches_the_unique_sync_job(): void
    {
        Bus::fake();

        $this->artisan('discord:sync-city-tiers')
            ->expectsOutput('Queued Discord city-tier role synchronization.')
            ->assertSuccessful();

        Bus::assertDispatched(SyncDiscordCityTierRolesJob::class);
    }

    private function createLinkedMember(
        Alliance $alliance,
        int $cityCount,
        string $discordId,
        string $position = 'MEMBER',
        bool $disabled = false,
        bool $unlinked = false,
    ): User {
        $nation = Nation::factory()->for($alliance)->create([
            'num_cities' => $cityCount,
            'alliance_position' => $position,
        ]);
        $user = User::factory()->create([
            'nation_id' => $nation->id,
            'disabled' => $disabled,
        ]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => $discordId,
            'unlinked_at' => $unlinked ? now() : null,
        ]);

        return $user;
    }
}
