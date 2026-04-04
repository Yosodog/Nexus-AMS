<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\LoanNotification;
use App\Services\LoanService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class LoanWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Notification::fake();
        SettingService::setLoanApplicationsEnabled(true);
        SettingService::setLoanPaymentsEnabled(true);
    }

    public function test_member_can_submit_a_loan_application_with_an_owned_account(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        $this->actingAs($user)
            ->post(route('loans.apply'), [
                'amount' => 250000,
                'account_id' => $account->id,
                'term_weeks' => 12,
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        $this->assertDatabaseHas('loans', [
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_member_cannot_submit_a_duplicate_pending_loan_application(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();

        Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
            'remaining_balance' => 250000,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
        ]);

        $this->actingAs($user)
            ->from(route('loans.index'))
            ->post(route('loans.apply'), [
                'amount' => 250000,
                'account_id' => $account->id,
                'term_weeks' => 12,
            ])
            ->assertRedirect(route('loans.index'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', 'You already have a pending loan application.');

        $this->assertSame(1, Loan::query()->count());
    }

    public function test_member_cannot_submit_a_loan_application_with_another_nations_account(): void
    {
        [$user] = $this->createMemberWithAccount();
        [, , $otherAccount] = $this->createMemberWithAccount(888);

        $this->actingAs($user)
            ->from(route('loans.index'))
            ->post(route('loans.apply'), [
                'amount' => 250000,
                'account_id' => $otherAccount->id,
                'term_weeks' => 12,
            ])
            ->assertRedirect(route('loans.index'))
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHas('alert-message', "You don't own that account");

        $this->assertSame(0, Loan::query()->count());
    }

    public function test_admin_can_approve_a_pending_loan_application(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
            'remaining_balance' => 250000,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
        ]);
        $admin = $this->createAdminWithPermission('manage-loans');

        $this->actingAs($admin)
            ->post(route('admin.loans.approve', ['Loan' => $loan->id]), [
                'amount' => 300000,
                'interest_rate' => 6.5,
                'term_weeks' => 16,
            ])
            ->assertRedirect(route('admin.loans'))
            ->assertSessionHas('alert-type', 'success');

        $loan->refresh();
        $account->refresh();

        $this->assertSame('approved', $loan->status);
        $this->assertNull($loan->pending_key);
        $this->assertNotNull($loan->approved_at);
        $this->assertSame(300000.0, (float) $loan->amount);
        $this->assertSame(300000.0, (float) $loan->remaining_balance);
        $this->assertSame('300000.00', number_format((float) $account->money, 2, '.', ''));

        $this->assertDatabaseHas('manual_transactions', [
            'account_id' => $account->id,
            'admin_id' => $admin->id,
            'money' => 300000,
        ]);

        Notification::assertSentTo(
            $nation,
            LoanNotification::class,
            fn (LoanNotification $notification): bool => $notification->status === 'approved'
                && $notification->loan->is($loan)
        );
    }

    public function test_admin_cannot_approve_their_own_loan_application(): void
    {
        [$admin, $nation, $account] = $this->createMemberWithAccount(999, admin: true);
        $admin = $this->attachAdminPermission($admin, 'manage-loans');
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
            'remaining_balance' => 250000,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.loans.approve', ['Loan' => $loan->id]), [
                'amount' => 300000,
                'interest_rate' => 6.5,
                'term_weeks' => 16,
            ])
            ->assertForbidden();

        $loan->refresh();

        $this->assertSame('pending', $loan->status);
        $this->assertSame(1, $loan->pending_key);
    }

    public function test_admin_can_deny_a_pending_loan_application(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount();
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
            'remaining_balance' => 250000,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
        ]);
        $admin = $this->createAdminWithPermission('manage-loans');

        $this->actingAs($admin)
            ->post(route('admin.loans.deny', ['Loan' => $loan->id]))
            ->assertRedirect(route('admin.loans'))
            ->assertSessionHas('alert-type', 'success');

        $loan->refresh();

        $this->assertSame('denied', $loan->status);
        $this->assertNull($loan->pending_key);

        Notification::assertSentTo(
            $nation,
            LoanNotification::class,
            fn (LoanNotification $notification): bool => $notification->status === 'denied'
                && $notification->loan->is($loan)
        );
    }

    public function test_approving_a_non_pending_loan_raises_a_validation_error(): void
    {
        [$admin] = $this->createMemberWithAccount(777250, admin: true);
        $admin = $this->attachAdminPermission($admin, 'manage-loans');
        [, $nation, $account] = $this->createMemberWithAccount(777251);
        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 250000,
            'term_weeks' => 12,
            'status' => 'denied',
            'pending_key' => null,
            'remaining_balance' => 250000,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 0,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
        ]);

        $this->actingAs($admin);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only pending loans can be approved.');

        app(LoanService::class)->approveLoan($loan, 300000, 6.5, 16);
    }

    public function test_repayment_allocates_interest_before_principal(): void
    {
        [$user, $nation, $account] = $this->createMemberWithAccount(777252);
        $account->forceFill(['money' => 500])->save();

        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 300,
            'interest_rate' => 10,
            'term_weeks' => 3,
            'status' => 'approved',
            'pending_key' => null,
            'remaining_balance' => 200,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 100,
            'past_due_amount' => 0,
            'accrued_interest_due' => 50,
            'approved_at' => now()->subWeek(),
            'next_due_date' => now()->addWeek(),
        ]);

        $this->actingAs($user);

        app(LoanService::class)->repayLoan($loan, $account->fresh(), 125);

        $loan->refresh();
        $account->refresh();

        /** @var LoanPayment $payment */
        $payment = LoanPayment::query()->where('loan_id', $loan->id)->sole();

        $this->assertSame(125.0, (float) $payment->amount);
        $this->assertSame(50.0, (float) $payment->interest_paid);
        $this->assertSame(75.0, (float) $payment->principal_paid);
        $this->assertSame(125.0, (float) $loan->remaining_balance);
        $this->assertSame(0.0, (float) $loan->accrued_interest_due);
        $this->assertSame('approved', $loan->status);
        $this->assertSame(375.0, (float) $account->money);
    }

    public function test_current_amount_due_includes_virtual_past_due_and_interest_for_overdue_loans(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-04 12:00:00'));

        [$user, $nation, $account] = $this->createMemberWithAccount(777253);
        $account->forceFill(['money' => 0])->save();

        $loan = Loan::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'amount' => 500,
            'interest_rate' => 10,
            'term_weeks' => 5,
            'status' => 'approved',
            'pending_key' => null,
            'remaining_balance' => 500,
            'weekly_interest_paid' => 0,
            'scheduled_weekly_payment' => 100,
            'past_due_amount' => 0,
            'accrued_interest_due' => 0,
            'approved_at' => now()->subDays(14),
            'next_due_date' => now()->startOfDay()->subDay(),
        ]);

        try {
            $service = app(LoanService::class);
            $due = $service->calculateCurrentAmountDue($loan->fresh(), now()->startOfDay());
            $preview = $service->previewPaymentBreakdown($loan->fresh(), 999999, now()->startOfDay());

            $this->assertSame(100.0, $due);
            $this->assertSame(50.0, (float) $preview['interest']);
            $this->assertSame(500.0, (float) $preview['principal']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(int $nationId = 777201, bool $admin = false): array
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
        [$admin] = $this->createMemberWithAccount(777299, admin: true);

        return $this->attachAdminPermission($admin, $permission);
    }

    private function attachAdminPermission(User $admin, string $permission): User
    {
        return $this->grantPermissions($admin, [$permission]);
    }
}
