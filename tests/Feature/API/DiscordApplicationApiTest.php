<?php

namespace Tests\Feature\API;

use App\Enums\ApplicationStatus;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Models\Application;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\AlliancePositionService;
use App\Services\ApplicationService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class DiscordApplicationApiTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.pw.alliance_id', 877);
        Cache::forever('alliances:membership:ids', [877]);
        SettingService::setApplicationsEnabled(true);
        SettingService::setApplicationsDiscordApplicantRoleId('applicant-role');
        SettingService::setApplicationsDiscordIaRoleId('ia-role');
        SettingService::setApplicationsDiscordMemberRoleId('member-role');
        SettingService::setApplicationsDiscordInterviewCategoryId('interview-category');
        SettingService::setApplicationsApprovalAnnouncementChannelId('announce-channel');
        SettingService::setApplicationsApprovalMessageTemplate('Welcome aboard');

        config()->set('services.discord_bot_key', 'discord-test-token');
    }

    public function test_store_returns_a_success_payload_for_an_eligible_applicant(): void
    {
        $service = $this->makeApplicationService([
            877001 => $this->makeApplicantNation(877001),
        ]);
        $this->app->instance(ApplicationService::class, $service);

        $response = $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/applications', [
            'nation_id' => 877001,
            'discord_user_id' => 'applicant-1',
            'discord_username' => 'alpha-user',
        ]);

        $response->assertCreated()
            ->assertJsonPath('application.nation_id', 877001)
            ->assertJsonPath('application.discord_user_id', 'applicant-1')
            ->assertJsonPath('application.status', ApplicationStatus::Pending->value)
            ->assertJsonPath('nation.id', 877001)
            ->assertJsonPath('config.applicant_role_id', 'applicant-role')
            ->assertJsonPath('config.join_url', sprintf(
                'https://politicsandwar.com/alliance/join/id=%d',
                app(AllianceMembershipService::class)->getPrimaryAllianceId()
            ));

        $this->assertDatabaseHas('applications', [
            'nation_id' => 877001,
            'discord_user_id' => 'applicant-1',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);
    }

    public function test_store_returns_a_structured_failure_payload_when_applications_are_disabled(): void
    {
        SettingService::setApplicationsEnabled(false);

        $service = $this->makeApplicationService([
            877002 => $this->makeApplicantNation(877002),
        ]);
        $this->app->instance(ApplicationService::class, $service);

        $response = $this->withHeaders($this->discordHeaders())->postJson('/api/v1/discord/applications', [
            'nation_id' => 877002,
            'discord_user_id' => 'applicant-2',
            'discord_username' => 'bravo-user',
        ]);

        $response->assertForbidden()
            ->assertExactJson([
                'error' => 'system_disabled',
                'message' => 'Applications are currently disabled.',
                'context' => [],
            ]);
    }

    public function test_attach_channel_and_message_logging_use_the_pending_application_channel(): void
    {
        $service = $this->makeApplicationService();
        $this->app->instance(ApplicationService::class, $service);

        $application = Application::query()->create([
            'nation_id' => 877003,
            'leader_name_snapshot' => 'Leader 877003',
            'discord_user_id' => 'applicant-3',
            'discord_username' => 'charlie-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/applications/attach-channel', [
                'application_id' => $application->id,
                'discord_channel_id' => 'channel-123',
            ])
            ->assertOk()
            ->assertJsonPath('application.discord_channel_id', 'channel-123');

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/applications/messages', [
                'discord_channel_id' => 'channel-123',
                'discord_message_id' => 'message-1',
                'discord_user_id' => 'staff-1',
                'discord_username' => 'Moderator',
                'content' => 'Initial interview response',
                'sent_at' => now()->timestamp,
                'is_staff' => true,
            ])
            ->assertOk()
            ->assertJsonPath('logged', true)
            ->assertJsonPath('message.discord_channel_id', 'channel-123');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'discord_channel_id' => 'channel-123',
        ]);
        $this->assertDatabaseHas('application_messages', [
            'application_id' => $application->id,
            'discord_message_id' => 'message-1',
            'discord_channel_id' => 'channel-123',
            'content' => 'Initial interview response',
            'is_staff' => 1,
        ]);
    }

    public function test_approve_endpoint_uses_the_mocked_alliance_position_service_and_updates_the_application(): void
    {
        $moderator = $this->createModerator('moderator-approve');
        $application = Application::query()->create([
            'nation_id' => 877004,
            'leader_name_snapshot' => 'Leader 877004',
            'discord_user_id' => 'applicant-approve',
            'discord_username' => 'delta-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);

        $alliancePositionService = $this->createMock(AlliancePositionService::class);
        $alliancePositionService->expects($this->once())
            ->method('approveMember')
            ->with(877004);

        $service = $this->makeApplicationService(
            [877004 => $this->makeApplicantNation(877004)],
            $alliancePositionService
        );
        $this->app->instance(ApplicationService::class, $service);

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/applications/approve', [
                'applicant_discord_id' => 'applicant-approve',
                'moderator_discord_id' => $moderator->activeDiscordAccount()->discord_id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('application.id', $application->id);

        $application->refresh();

        $this->assertSame(ApplicationStatus::Approved, $application->status);
        $this->assertNull($application->pending_key);
        $this->assertSame(
            $moderator->activeDiscordAccount()->discord_id,
            $application->approved_by_discord_id
        );
    }

    public function test_deny_endpoint_uses_the_mocked_alliance_position_service_and_updates_the_application(): void
    {
        $moderator = $this->createModerator('moderator-deny');
        $application = Application::query()->create([
            'nation_id' => 877005,
            'leader_name_snapshot' => 'Leader 877005',
            'discord_user_id' => 'applicant-deny',
            'discord_username' => 'echo-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);

        $alliancePositionService = $this->createMock(AlliancePositionService::class);
        $alliancePositionService->expects($this->once())
            ->method('removeMember')
            ->with(877005);

        $service = $this->makeApplicationService(
            [877005 => $this->makeApplicantNation(877005)],
            $alliancePositionService
        );
        $this->app->instance(ApplicationService::class, $service);

        $this->withHeaders($this->discordHeaders())
            ->postJson('/api/v1/discord/applications/deny', [
                'applicant_discord_id' => 'applicant-deny',
                'moderator_discord_id' => $moderator->activeDiscordAccount()->discord_id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'denied')
            ->assertJsonPath('application.id', $application->id);

        $application->refresh();

        $this->assertSame(ApplicationStatus::Denied, $application->status);
        $this->assertNull($application->pending_key);
        $this->assertSame(
            $moderator->activeDiscordAccount()->discord_id,
            $application->denied_by_discord_id
        );
    }

    /**
     * @return array<string, string>
     */
    private function discordHeaders(): array
    {
        return [
            'Authorization' => 'Bearer discord-test-token',
            'Accept' => 'application/json',
        ];
    }

    private function createModerator(string $discordId): User
    {
        $nation = Nation::factory()->create([
            'id' => random_int(900000, 999999),
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $user = $this->grantPermissions(
            User::factory()->verified()->admin()->create([
                'nation_id' => $nation->id,
            ]),
            ['manage-applications']
        );

        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => $discordId,
        ]);

        return $user->fresh();
    }

    private function makeApplicantNation(int $nationId): GraphQlNation
    {
        $nation = new GraphQlNation;
        $nation->id = $nationId;
        $nation->leader_name = "Leader {$nationId}";
        $nation->alliance_id = app(AllianceMembershipService::class)->getPrimaryAllianceId();
        $nation->alliance_position = 'APPLICANT';

        return $nation;
    }

    /**
     * @param  array<int, GraphQlNation>  $nations
     */
    private function makeApplicationService(
        array $nations = [],
        ?AlliancePositionService $alliancePositionService = null
    ): ApplicationService {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService ??= $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService, $nations) extends ApplicationService
        {
            /**
             * @param  array<int, GraphQlNation>  $nations
             */
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
}
