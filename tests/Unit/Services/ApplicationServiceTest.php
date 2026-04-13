<?php

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Jobs\SyncApplicationAllianceState;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AlliancePositionService;
use App\Services\ApplicationService;
use App\Services\AuditLogger;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsTestUsers;
use Tests\FeatureTestCase;

class ApplicationServiceTest extends FeatureTestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::setApplicationsEnabled(true);
        config()->set('services.pw.alliance_id', 877);
        cache()->forever('alliances:membership:ids', [877]);
    }

    public function test_assert_applicant_eligible_rejects_non_applicants(): void
    {
        $primaryAllianceId = app(AllianceMembershipService::class)->getPrimaryAllianceId();

        $service = $this->makeService([
            877100 => $this->makeNation(877100, $primaryAllianceId, 'MEMBER'),
        ]);

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('The nation must be marked as an applicant in the alliance.');

        $service->createApplicationFromDiscord(877100, 'discord-a', 'user-a');
    }

    public function test_create_application_uses_local_nation_snapshot_when_it_already_shows_an_eligible_applicant(): void
    {
        Nation::factory()->create([
            'id' => 877103,
            'leader_name' => 'Local Leader',
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'APPLICANT',
            'alliance_position_id' => 1,
        ]);

        $service = $this->makeApiGuardedService();

        $application = $service->createApplicationFromDiscord(877103, 'discord-local-applicant', 'local-user');

        $this->assertSame(877103, $application->nation_id);
        $this->assertSame('Local Leader', $application->leader_name_snapshot);
    }

    public function test_get_nation_uses_local_nation_snapshot_without_hitting_the_api(): void
    {
        Nation::factory()->create([
            'id' => 877104,
            'leader_name' => 'Local Nation',
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
        ]);

        $service = $this->makeApiGuardedService();

        $nation = $service->publicGetNation(877104);

        $this->assertSame(877104, $nation->id);
        $this->assertSame('Local Nation', $nation->leader_name);
        $this->assertSame('MEMBER', $nation->alliance_position);
    }

    public function test_resolve_moderator_rejects_unlinked_discord_accounts(): void
    {
        $service = $this->makeInspectableService();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Moderator account is not linked to Nexus.');

        $service->publicResolveModerator('missing-discord');
    }

    public function test_resolve_moderator_rejects_users_without_application_permission(): void
    {
        $nation = Nation::factory()->create();
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => 'discord-no-access',
        ]);

        $service = $this->makeInspectableService();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('You do not have permission to manage applications.');

        $service->publicResolveModerator('discord-no-access');
    }

    public function test_approve_marks_the_application_locally_and_queues_the_alliance_sync_job(): void
    {
        Queue::fake();

        $moderator = $this->createModerator('discord-mod-1');
        $application = Application::query()->create([
            'nation_id' => 877101,
            'leader_name_snapshot' => 'Leader',
            'discord_user_id' => 'discord-applicant',
            'discord_username' => 'applicant',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);

        $service = $this->makeService(
            [877101 => $this->makeNation(877101, 877, 'APPLICANT')]
        );

        $approvedApplication = $service->approveByDiscordUser('discord-applicant', $moderator->activeDiscordAccount()->discord_id);

        $application->refresh();

        $this->assertSame($application->id, $approvedApplication->id);
        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertNull($application->pending_key);
        $this->assertSame($moderator->activeDiscordAccount()->discord_id, $application->approved_by_discord_id);

        Queue::assertPushed(SyncApplicationAllianceState::class, function (SyncApplicationAllianceState $job) use ($application, $moderator): bool {
            return $job->applicationId === $application->id
                && $job->targetStatus === ApplicationStatus::Approved
                && $job->moderatorUserId === $moderator->id
                && $job->moderatorName === $moderator->name;
        });
    }

    public function test_deny_marks_the_application_locally_and_queues_the_alliance_sync_job(): void
    {
        Queue::fake();

        $moderator = $this->createModerator('discord-mod-2');
        $application = Application::query()->create([
            'nation_id' => 877102,
            'leader_name_snapshot' => 'Leader',
            'discord_user_id' => 'discord-deny',
            'discord_username' => 'applicant',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);

        $service = $this->makeService(
            [877102 => $this->makeNation(877102, 877, 'APPLICANT')]
        );

        $deniedApplication = $service->denyByDiscordUser('discord-deny', $moderator->activeDiscordAccount()->discord_id);

        $application->refresh();

        $this->assertSame($application->id, $deniedApplication->id);
        $this->assertSame(ApplicationStatus::Denied, $application->status);
        $this->assertNull($application->pending_key);
        $this->assertSame($moderator->activeDiscordAccount()->discord_id, $application->denied_by_discord_id);

        Queue::assertPushed(SyncApplicationAllianceState::class, function (SyncApplicationAllianceState $job) use ($application, $moderator): bool {
            return $job->applicationId === $application->id
                && $job->targetStatus === ApplicationStatus::Denied
                && $job->moderatorUserId === $moderator->id
                && $job->moderatorName === $moderator->name;
        });
    }

    public function test_sync_application_alliance_state_job_approves_the_member_in_politics_and_war(): void
    {
        $application = Application::query()->create([
            'nation_id' => 877105,
            'leader_name_snapshot' => 'Leader',
            'discord_user_id' => 'discord-job-approve',
            'discord_username' => 'applicant',
            'status' => ApplicationStatus::Approved->value,
            'pending_key' => null,
            'approved_at' => now(),
            'approved_by_discord_id' => 'discord-mod-3',
        ]);

        $positionService = $this->createMock(AlliancePositionService::class);
        $positionService->expects($this->once())
            ->method('approveMember')
            ->with(877105);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('success');

        $job = new SyncApplicationAllianceState(
            applicationId: $application->id,
            targetStatus: ApplicationStatus::Approved,
            moderatorUserId: 55,
            moderatorName: 'Mod',
        );

        $job->handle($positionService, app(AllianceMembershipService::class), $auditLogger);
    }

    public function test_sync_application_alliance_state_job_denies_the_member_in_politics_and_war_when_still_in_alliance(): void
    {
        Nation::factory()->create([
            'id' => 877106,
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'APPLICANT',
            'alliance_position_id' => 1,
        ]);

        $application = Application::query()->create([
            'nation_id' => 877106,
            'leader_name_snapshot' => 'Leader',
            'discord_user_id' => 'discord-job-deny',
            'discord_username' => 'applicant',
            'status' => ApplicationStatus::Denied->value,
            'pending_key' => null,
            'denied_at' => now(),
            'denied_by_discord_id' => 'discord-mod-4',
        ]);

        $positionService = $this->createMock(AlliancePositionService::class);
        $positionService->expects($this->once())
            ->method('removeMember')
            ->with(877106);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())
            ->method('denied');

        $job = new SyncApplicationAllianceState(
            applicationId: $application->id,
            targetStatus: ApplicationStatus::Denied,
            moderatorUserId: 56,
            moderatorName: 'Mod 2',
        );

        $job->handle($positionService, app(AllianceMembershipService::class), $auditLogger);
    }

    private function createModerator(string $discordId): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
        ]);
        $user = $this->grantPermissions(
            User::factory()->verified()->admin()->create(['nation_id' => $nation->id]),
            ['manage-applications']
        );

        $this->attachDiscordAccount($user, ['discord_id' => $discordId]);

        return $user->fresh();
    }

    private function makeNation(int $id, int $allianceId, string $position): GraphQlNation
    {
        $nation = new GraphQlNation;
        $nation->id = $id;
        $nation->leader_name = 'Leader '.$id;
        $nation->alliance_id = $allianceId;
        $nation->alliance_position = $position;

        return $nation;
    }

    /**
     * @param  array<int, GraphQlNation>  $nations
     */
    private function makeService(
        array $nations = [],
        ?AlliancePositionService $alliancePositionService = null
    ): ApplicationService {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService ??= $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService, $nations) extends ApplicationService
        {
            public function __construct(
                AllianceMembershipService $membershipService,
                AlliancePositionService $alliancePositionService,
                private readonly array $nations,
            ) {
                parent::__construct($membershipService, $alliancePositionService);
            }

            protected function fetchNation(int $nationId): GraphQlNation
            {
                return $this->nations[$nationId];
            }
        };
    }

    private function makeInspectableService(): object
    {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService = $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService) extends ApplicationService
        {
            public function publicResolveModerator(string $discordId): User
            {
                return $this->resolveModerator($discordId);
            }
        };
    }

    private function makeApiGuardedService(): object
    {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService = $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService) extends ApplicationService
        {
            protected function queryNationFromApi(int $nationId): GraphQlNation
            {
                throw new RuntimeException('Remote nation API should not be called.');
            }

            public function publicGetNation(int $nationId): GraphQlNation
            {
                return $this->getNation($nationId);
            }
        };
    }
}
