<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\CityGrant;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\ManualDisbursement;
use App\Models\Nation;
use App\Models\User;
use App\Models\WarAidRequest;
use App\Services\LoanService;
use App\Services\SettingService;
use App\Services\WarAidService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class ManualDisbursementWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setGrantApprovalsEnabled(true);
        SettingService::setLoanApplicationsEnabled(true);
        SettingService::setLoanPaymentsEnabled(true);
        SettingService::setWarAidEnabled(true);
    }

    public function test_replaying_the_same_manual_loan_uuid_returns_the_original_result_without_a_second_credit(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $admin = $this->createAdminWithPermission('manage-loans');
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 125000.50,
            'interest_rate' => 4.25,
            'term_weeks' => 12,
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($admin)
            ->from(route('admin.loans'))
            ->post(route('admin.manual-disbursements.loans'), $payload)
            ->assertRedirect(route('admin.loans'))
            ->assertSessionHas('alert-type', 'success');

        $this->actingAs($admin)
            ->from(route('admin.loans'))
            ->post(route('admin.manual-disbursements.loans'), $payload)
            ->assertRedirect(route('admin.loans'))
            ->assertSessionHas('alert-type', 'success');

        $loan = Loan::query()->sole();
        $account->refresh();

        $this->assertSame('approved', $loan->status);
        $this->assertNull($loan->pending_key);
        $this->assertSame(125000.5, (float) $account->money);
        $this->assertDatabaseCount('loans', 1);
        $this->assertDatabaseCount('manual_disbursements', 1);
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertDatabaseHas('manual_disbursements', [
            'idempotency_key' => $idempotencyKey,
            'type' => 'loan',
            'workflow_id' => $loan->id,
            'created_by' => $admin->id,
        ]);
    }

    public function test_manual_disbursement_uuid_is_unique_at_the_database_level(): void
    {
        $admin = $this->createAdminWithPermission('manage-loans');
        $idempotencyKey = (string) Str::uuid();

        ManualDisbursement::query()->create([
            'idempotency_key' => $idempotencyKey,
            'type' => ManualDisbursement::TYPE_LOAN,
            'created_by' => $admin->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ManualDisbursement::query()->create([
            'idempotency_key' => $idempotencyKey,
            'type' => ManualDisbursement::TYPE_LOAN,
            'created_by' => $admin->id,
        ]);
    }

    public function test_failed_manual_grant_approval_rolls_back_the_synthetic_request_and_idempotency_record(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $admin = $this->createAdminWithPermission('manage-grants');
        $grant = $this->createGrant(['is_enabled' => false]);

        $this->actingAs($admin)
            ->from(route('admin.grants'))
            ->post(route('admin.manual-disbursements.grants'), [
                'grant_id' => $grant->id,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.grants'))
            ->assertSessionHas('alert-type', 'error');

        $this->assertDatabaseCount('grant_applications', 0);
        $this->assertDatabaseCount('manual_disbursements', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_failed_manual_city_grant_approval_rolls_back_the_synthetic_request_and_idempotency_record(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $admin = $this->createAdminWithPermission('manage-city-grants');
        $cityGrant = CityGrant::query()->create([
            'description' => 'Disabled growth support',
            'enabled' => false,
            'grant_amount' => 100,
            'city_number' => 12,
            'requirements' => [],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.grants.city'))
            ->post(route('admin.manual-disbursements.city-grants'), [
                'city_grant_id' => $cityGrant->id,
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'grant_amount' => 150000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.grants.city'))
            ->assertSessionHas('alert-type', 'error');

        $this->assertDatabaseCount('city_grant_requests', 0);
        $this->assertDatabaseCount('manual_disbursements', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_manual_loan_sets_the_pending_guard_and_rolls_back_when_approval_fails(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $admin = $this->createAdminWithPermission('manage-loans');

        $this->mock(LoanService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('approveLoan')
                ->once()
                ->withArgs(function (Loan $loan): bool {
                    $this->assertSame(1, $loan->pending_key);

                    return true;
                })
                ->andThrow(ValidationException::withMessages([
                    'loan' => 'Injected loan approval failure.',
                ]));
        });

        $this->actingAs($admin)
            ->from(route('admin.loans'))
            ->post(route('admin.manual-disbursements.loans'), [
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'amount' => 100000,
                'interest_rate' => 5,
                'term_weeks' => 10,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.loans'))
            ->assertSessionHasErrors('loan');

        $this->assertDatabaseCount('loans', 0);
        $this->assertDatabaseCount('manual_disbursements', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    public function test_manual_war_aid_sets_the_pending_guard_and_rolls_back_when_approval_fails(): void
    {
        [, $nation, $account] = $this->createMemberWithAccount();
        $admin = $this->createAdminWithPermission('manage-war-aid');

        $this->mock(WarAidService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('approveAidRequest')
                ->once()
                ->withArgs(function (WarAidRequest $request): bool {
                    $this->assertSame(1, $request->pending_key);

                    return true;
                })
                ->andThrow(ValidationException::withMessages([
                    'request' => 'Injected war aid approval failure.',
                ]));
        });

        $this->actingAs($admin)
            ->from(route('admin.war-aid'))
            ->post(route('admin.manual-disbursements.war-aid'), [
                'nation_id' => $nation->id,
                'account_id' => $account->id,
                'money' => 50000,
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.war-aid'))
            ->assertSessionHasErrors('request');

        $this->assertDatabaseCount('war_aid_requests', 0);
        $this->assertDatabaseCount('manual_disbursements', 0);
        $this->assertDatabaseCount('manual_transactions', 0);
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777501, bool $admin = false): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $userFactory = $admin
            ? User::factory()->verified()->admin()
            : User::factory()->verified();

        $user = $userFactory->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        if ($admin) {
            $this->attachDiscordAccount($user);
        }

        return [$user, $nation, $account];
    }

    private function createAdminWithPermission(string $permission): User
    {
        [$admin] = $this->createMemberWithAccount(777599, admin: true);

        return $this->grantPermissions($admin, [$permission]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGrant(array $overrides = []): Grants
    {
        $grant = new Grants;
        $grant->name = $overrides['name'] ?? 'Manual Growth Grant';
        $grant->slug = $overrides['slug'] ?? 'manual-growth-grant';
        $grant->description = $overrides['description'] ?? 'Support for growth.';
        $grant->money = $overrides['money'] ?? 100000;
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
        $grant->is_enabled = $overrides['is_enabled'] ?? true;
        $grant->is_one_time = false;
        $grant->save();

        return $grant;
    }
}
