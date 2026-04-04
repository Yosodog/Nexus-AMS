<?php

namespace Tests\Feature\Workflows;

use App\Enums\ApplicationStatus;
use App\Exceptions\ApplicationException;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Models\Application;
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

class ApplicationAdminWorkflowTest extends TestCase
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
    }

    public function test_discord_application_creation_succeeds_for_an_eligible_applicant(): void
    {
        $service = $this->makeApplicationServiceForNation($this->makeApplicantNation(877001));

        $application = $service->createApplicationFromDiscord(877001, '1000001', 'alpha-user');

        $this->assertSame(877001, $application->nation_id);
        $this->assertSame(ApplicationStatus::Pending, $application->status);
        $this->assertSame(1, $application->pending_key);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'nation_id' => 877001,
            'discord_user_id' => '1000001',
            'discord_username' => 'alpha-user',
            'status' => ApplicationStatus::Pending->value,
            'pending_key' => 1,
        ]);
    }

    public function test_discord_application_creation_rejects_a_duplicate_pending_nation(): void
    {
        Application::query()->create([
            'nation_id' => 877002,
            'leader_name_snapshot' => 'Leader 2',
            'discord_user_id' => '1000002',
            'discord_username' => 'existing-user',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);

        $service = $this->makeApplicationServiceForNation($this->makeApplicantNation(877002));

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('An application is already pending for this nation or Discord user.');

        try {
            $service->createApplicationFromDiscord(877002, '1000999', 'new-user');
        } catch (ApplicationException $exception) {
            $this->assertSame('pending_application_exists', $exception->error);
            throw $exception;
        }
    }

    public function test_discord_application_creation_rejects_a_duplicate_pending_discord_user(): void
    {
        Application::query()->create([
            'nation_id' => 877003,
            'leader_name_snapshot' => 'Leader 3',
            'discord_user_id' => '1000003',
            'discord_username' => 'existing-user',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);

        $service = $this->makeApplicationServiceForNation($this->makeApplicantNation(877004));

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('An application is already pending for this nation or Discord user.');

        try {
            $service->createApplicationFromDiscord(877004, '1000003', 'existing-user');
        } catch (ApplicationException $exception) {
            $this->assertSame('pending_application_exists', $exception->error);
            throw $exception;
        }
    }

    public function test_admin_can_cancel_a_pending_application(): void
    {
        $application = Application::query()->create([
            'nation_id' => 877005,
            'leader_name_snapshot' => 'Leader 5',
            'discord_user_id' => '1000005',
            'discord_username' => 'applicant-five',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);
        $admin = $this->createAdminWithPermission('manage-applications');

        $this->actingAs($admin)
            ->post(route('admin.applications.cancel', ['application' => $application->id]))
            ->assertRedirect(route('admin.applications.show', ['application' => $application->id]))
            ->assertSessionHas('alert-type', 'success');

        $application->refresh();

        $this->assertSame(ApplicationStatus::Cancelled, $application->status);
        $this->assertNull($application->pending_key);
        $this->assertNotNull($application->cancelled_at);
        $this->assertSame($admin->activeDiscordAccount()?->discord_id, $application->cancelled_by_discord_id);
    }

    public function test_admin_without_permission_cannot_cancel_an_application(): void
    {
        $application = Application::query()->create([
            'nation_id' => 877006,
            'leader_name_snapshot' => 'Leader 6',
            'discord_user_id' => '1000006',
            'discord_username' => 'applicant-six',
            'status' => ApplicationStatus::Pending,
            'pending_key' => 1,
        ]);
        [$admin] = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.applications.cancel', ['application' => $application->id]))
            ->assertRedirect(route('admin.applications.show', ['application' => $application->id]))
            ->assertSessionHas('alert-type', 'danger')
            ->assertSessionHas('alert-message', 'You do not have permission to cancel applications.');

        $application->refresh();

        $this->assertSame(ApplicationStatus::Pending, $application->status);
        $this->assertSame(1, $application->pending_key);
    }

    /**
     * @return array{0: User, 1: Nation}
     */
    private function createAdmin(): array
    {
        $nation = Nation::factory()->create([
            'id' => 877099,
            'alliance_id' => app(AllianceMembershipService::class)->getPrimaryAllianceId(),
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $admin = User::factory()->verified()->admin()->create([
            'nation_id' => $nation->id,
        ]);

        $this->attachDiscordAccount($admin, [
            'discord_id' => '999999999',
        ]);

        return [$admin, $nation];
    }

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createAdmin();

        return $this->grantPermissions($admin, [$permission]);
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

    private function makeApplicationServiceForNation(GraphQlNation $nation): ApplicationService
    {
        $membershipService = app(AllianceMembershipService::class);
        $alliancePositionService = $this->createMock(AlliancePositionService::class);

        return new class($membershipService, $alliancePositionService, $nation) extends ApplicationService
        {
            public function __construct(
                AllianceMembershipService $membershipService,
                AlliancePositionService $alliancePositionService,
                private readonly GraphQlNation $nation,
            ) {
                parent::__construct($membershipService, $alliancePositionService);
            }

            protected function fetchNation(int $nationId): GraphQlNation
            {
                return $this->nation;
            }
        };
    }
}
