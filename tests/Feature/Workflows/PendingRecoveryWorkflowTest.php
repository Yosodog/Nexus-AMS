<?php

namespace Tests\Feature\Workflows;

use App\Enums\ApplicationStatus;
use App\Models\Account;
use App\Models\Application;
use App\Models\CityGrantRequest;
use App\Models\DepositRequest;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\RebuildingRequest;
use App\Models\RebuildingTier;
use App\Models\User;
use App\Models\WarAidRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class PendingRecoveryWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    #[DataProvider('stalePendingProvider')]
    public function test_admin_can_release_each_supported_stale_pending_type(
        string $type,
        string $table,
        array $oldPending,
        array $freshPending,
        array $releasedState,
        array $freshState,
    ): void {
        [$admin] = $this->createAdminWithPermission('view-diagnostic-info');

        $oldId = $this->createPendingRecord($type, $oldPending, now()->subHours(72));
        $freshId = $this->createPendingRecord($type, $freshPending, now()->subHours(2));

        $this->actingAs($admin)
            ->post(route('admin.settings.pending-requests.release-stale'), [
                'type' => $type,
                'older_than_hours' => 48,
            ])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas($table, ['id' => $oldId, ...$releasedState]);
        $this->assertDatabaseHas($table, ['id' => $freshId, ...$freshState]);
    }

    public function test_admin_without_diagnostic_permission_cannot_release_stale_pending_requests(): void
    {
        [$admin] = $this->createAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.pending-requests.release-stale'), [
                'type' => 'applications',
                'older_than_hours' => 48,
            ])
            ->assertForbidden();
    }

    public static function stalePendingProvider(): array
    {
        return [
            'war aid' => [
                'war_aid',
                'war_aid_requests',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'denied', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
            'applications' => [
                'applications',
                'applications',
                ['status' => ApplicationStatus::Pending->value, 'pending_key' => 1],
                ['status' => ApplicationStatus::Pending->value, 'pending_key' => 1],
                ['status' => ApplicationStatus::Cancelled->value, 'pending_key' => null],
                ['status' => ApplicationStatus::Pending->value, 'pending_key' => 1],
            ],
            'loans' => [
                'loans',
                'loans',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'denied', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
            'deposit requests' => [
                'deposit_requests',
                'deposit_requests',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'expired', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
            'grant applications' => [
                'grant_applications',
                'grant_applications',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'denied', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
            'city grant requests' => [
                'city_grant_requests',
                'city_grant_requests',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'denied', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
            'rebuilding requests' => [
                'rebuilding_requests',
                'rebuilding_requests',
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'pending', 'pending_key' => 1],
                ['status' => 'expired', 'pending_key' => null],
                ['status' => 'pending', 'pending_key' => 1],
            ],
        ];
    }

    /**
     * @return array{0: User, 1: Nation}
     */
    private function createAdmin(): array
    {
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);

        $nation = Nation::factory()->create([
            'id' => 777999,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $admin = User::factory()->verified()->admin()->create([
            'nation_id' => $nation->id,
        ]);

        $this->attachDiscordAccount($admin);

        return [$admin, $nation];
    }

    /**
     * @return array{0: User, 1: Nation}
     */
    private function createAdminWithPermission(string $permission): array
    {
        [$admin, $nation] = $this->createAdmin();

        return [$this->grantPermissions($admin, [$permission]), $nation];
    }

    private function createPendingRecord(string $type, array $overrides, Carbon $createdAt): int
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        $record = match ($type) {
            'war_aid' => WarAidRequest::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'note' => 'Old request',
                'money' => 1000,
                ...$overrides,
            ]),
            'applications' => Application::query()->create([
                'nation_id' => $nation->id,
                'leader_name_snapshot' => 'Applicant',
                'discord_user_id' => 'discord-'.$nation->id.'-'.$createdAt->timestamp,
                'discord_username' => 'applicant-'.$nation->id,
                ...$overrides,
            ]),
            'loans' => Loan::query()->create([
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'amount' => 250000,
                'term_weeks' => 12,
                'remaining_balance' => 250000,
                'weekly_interest_paid' => 0,
                'scheduled_weekly_payment' => 0,
                'past_due_amount' => 0,
                'accrued_interest_due' => 0,
                ...$overrides,
            ]),
            'deposit_requests' => DepositRequest::query()->create([
                'account_id' => $account->id,
                'deposit_code' => 'CODE'.$nation->id.$createdAt->format('His'),
                ...$overrides,
            ]),
            'grant_applications' => GrantApplication::query()->create([
                'grant_id' => $this->createGrant()->id,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                ...$overrides,
            ]),
            'city_grant_requests' => CityGrantRequest::query()->create([
                'city_number' => 6,
                'grant_amount' => 200000,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                ...$overrides,
            ]),
            'rebuilding_requests' => RebuildingRequest::query()->create([
                'cycle_id' => 1,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'tier_id' => $this->createTier()->id,
                'city_count_snapshot' => 5,
                'target_infrastructure_snapshot' => 1700,
                'estimated_amount' => 200000,
                ...$overrides,
            ]),
        };

        $record->timestamps = false;
        $record->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $record->id;
    }

    private function createGrant(): Grants
    {
        $suffix = uniqid();

        $grant = new Grants;
        $grant->name = 'Pending Cleanup Grant '.$suffix;
        $grant->slug = 'pending-cleanup-grant-'.$suffix;
        $grant->description = 'Cleanup';
        $grant->money = 100000;
        $grant->coal = 0;
        $grant->oil = 0;
        $grant->uranium = 0;
        $grant->iron = 0;
        $grant->bauxite = 0;
        $grant->lead = 0;
        $grant->gasoline = 0;
        $grant->munitions = 0;
        $grant->steel = 0;
        $grant->aluminum = 0;
        $grant->food = 0;
        $grant->validation_rules = [];
        $grant->is_enabled = true;
        $grant->is_one_time = false;
        $grant->save();

        return $grant;
    }

    private function createTier(): RebuildingTier
    {
        return RebuildingTier::query()->create([
            'name' => 'Cleanup Tier '.uniqid(),
            'min_city_count' => 1,
            'max_city_count' => 10,
            'target_infrastructure' => 1700,
            'is_active' => true,
            'requirements' => [],
        ]);
    }
}
