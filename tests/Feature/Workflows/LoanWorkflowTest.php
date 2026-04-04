<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\User;
use App\Notifications\LoanNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
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
