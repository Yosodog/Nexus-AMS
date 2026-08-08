<?php

namespace Tests\Feature;

use App\Enums\LoanStatus;
use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\DirectDepositEnrollment;
use App\Models\DiscordAccount;
use App\Models\Loan;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_reloads_the_locked_account_before_checking_its_balance(): void
    {
        [$nation, $account] = $this->createNationAndAccount(810001);
        $staleAccount = Account::query()->findOrFail($account->id);

        DB::table('accounts')
            ->where('id', $account->id)
            ->update(['money' => 25]);

        try {
            AccountService::deleteAccount($staleAccount, $nation->id);
            $this->fail('A newly funded account was deleted.');
        } catch (UserErrorException $exception) {
            $this->assertSame('The account is not empty.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('accounts', ['id' => $account->id]);
        $this->assertSame(25.0, (float) $account->refresh()->money);
    }

    public function test_pending_outgoing_member_transfer_blocks_account_deletion(): void
    {
        [$nation, $account, $user] = $this->createNationAndAccount(810002, createUser: true);
        [, $destination] = $this->createNationAndAccount(810003);
        $this->createPendingMemberTransfer($account, $destination, $user);

        $this->assertPendingMemberTransferBlocksDeletion($account, $nation->id);
    }

    public function test_pending_incoming_member_transfer_blocks_account_deletion(): void
    {
        [, $source, $user] = $this->createNationAndAccount(810004, createUser: true);
        [$nation, $account] = $this->createNationAndAccount(810005);
        $this->createPendingMemberTransfer($source, $account, $user);

        $this->assertPendingMemberTransferBlocksDeletion($account, $nation->id);
    }

    public function test_service_rechecks_account_ownership_inside_the_delete_transaction(): void
    {
        [, $account] = $this->createNationAndAccount(810006);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage("You don't own that account");

        AccountService::deleteAccount($account, 810007);
    }

    public function test_empty_account_without_pending_work_can_be_deleted(): void
    {
        [$nation, $account] = $this->createNationAndAccount(810008);

        AccountService::deleteAccount($account, $nation->id);

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    #[DataProvider('accountDeletionBlockingLoanStatusProvider')]
    public function test_pending_and_active_loans_block_account_deletion(LoanStatus $status): void
    {
        [$nation, $account] = $this->createNationAndAccount(810016);
        $this->createLoanWithStatus($account, $status);

        try {
            AccountService::deleteAccount($account, $nation->id);
            $this->fail("An account with a {$status->value} loan was deleted.");
        } catch (UserErrorException $exception) {
            $this->assertSame('The account has pending or active loans.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('accounts', ['id' => $account->id]);
    }

    #[DataProvider('terminalLoanStatusProvider')]
    public function test_terminal_loans_do_not_block_account_deletion(LoanStatus $status): void
    {
        [$nation, $account] = $this->createNationAndAccount(810017);
        $this->createLoanWithStatus($account, $status);

        AccountService::deleteAccount($account, $nation->id);

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_account_delete_endpoint_rejects_missing_and_array_ids(): void
    {
        [, $account, $user] = $this->createNationAndAccount(810012, createUser: true);
        $this->attachDiscordAccount($user);

        $this->actingAs($user)
            ->from(route('accounts'))
            ->post(route('accounts.delete.post'))
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors('account_id');

        $this->post(route('accounts.delete.post'), [
            'account_id' => [$account->id],
        ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors('account_id');

        $this->assertNotSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_account_delete_endpoint_scopes_lookup_to_the_owner(): void
    {
        [, $ownedAccount, $user] = $this->createNationAndAccount(810013, createUser: true);
        [, $foreignAccount] = $this->createNationAndAccount(810014);
        $this->attachDiscordAccount($user);

        $this->actingAs($user)
            ->post(route('accounts.delete.post'), [
                'account_id' => $foreignAccount->id,
            ])
            ->assertNotFound();

        $this->assertNotSoftDeleted('accounts', ['id' => $ownedAccount->id]);
        $this->assertNotSoftDeleted('accounts', ['id' => $foreignAccount->id]);
    }

    public function test_account_delete_endpoint_deletes_an_owned_empty_account(): void
    {
        [, $account, $user] = $this->createNationAndAccount(810015, createUser: true);
        $this->attachDiscordAccount($user);

        $this->actingAs($user)
            ->post(route('accounts.delete.post'), [
                'account_id' => $account->id,
            ])
            ->assertRedirect(route('accounts'));

        $this->assertSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_direct_deposit_account_cannot_be_deleted(): void
    {
        [$nation, $account] = $this->createNationAndAccount(810009);

        DirectDepositEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => 123,
            'enrolled_at' => now(),
        ]);

        try {
            AccountService::deleteAccount($account, $nation->id);
            $this->fail('An account selected for Direct Deposit was deleted.');
        } catch (UserErrorException $exception) {
            $this->assertSame(
                'Disenroll from Direct Deposit before deleting this account.',
                $exception->getMessage(),
            );
        }

        $this->assertNotSoftDeleted('accounts', ['id' => $account->id]);
    }

    public function test_direct_deposit_component_handles_an_enrollment_with_a_deleted_account(): void
    {
        [$nation, $deletedAccount] = $this->createNationAndAccount(810010);
        $activeAccount = new Account;
        $activeAccount->nation_id = $nation->id;
        $activeAccount->name = 'Secondary';
        $activeAccount->save();

        $enrollment = DirectDepositEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $deletedAccount->id,
            'previous_tax_id' => 123,
            'enrolled_at' => now(),
        ]);

        $deletedAccount->delete();
        $enrollment->load('account');

        $html = view('accounts.components.direct_deposit', [
            'enrollment' => $enrollment,
            'accounts' => collect([$activeAccount]),
            'bracket' => null,
            'gcEnrollment' => null,
        ])->render();

        $this->assertStringContainsString('Not enrolled', $html);
        $this->assertStringNotContainsString('deposits are heading to', $html);
    }

    public function test_admin_direct_deposit_component_handles_an_enrollment_with_a_deleted_account(): void
    {
        [$nation, $account, $user] = $this->createNationAndAccount(810011, createUser: true);

        $enrollment = DirectDepositEnrollment::query()->create([
            'nation_id' => $nation->id,
            'account_id' => $account->id,
            'previous_tax_id' => 123,
            'enrolled_at' => now(),
        ]);

        $account->delete();
        $enrollment->load('account');

        Gate::define('view-dd', fn (): bool => true);
        Gate::define('manage-dd', fn (): bool => false);

        $this->actingAs($user)
            ->view('admin.accounts.direct_deposit', [
                'ddTaxId' => 456,
                'fallbackTaxId' => 789,
                'brackets' => collect(),
                'enrollments' => collect([$enrollment]),
            ])
            ->assertSee('Deleted account');
    }

    public static function accountDeletionBlockingLoanStatusProvider(): iterable
    {
        yield LoanStatus::Pending->value => [LoanStatus::Pending];

        foreach (LoanStatus::activeValues() as $status) {
            yield $status => [LoanStatus::from($status)];
        }
    }

    public static function terminalLoanStatusProvider(): iterable
    {
        foreach (LoanStatus::cases() as $status) {
            if ($status->isTerminal()) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * @return array{0: Nation, 1: Account, 2?: User}
     */
    private function createNationAndAccount(int $nationId, bool $createUser = false): array
    {
        $nation = Nation::factory()->create(['id' => $nationId]);
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->save();

        if (! $createUser) {
            return [$nation, $account];
        }

        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);

        return [$nation, $account, $user];
    }

    private function attachDiscordAccount(User $user): void
    {
        DiscordAccount::factory()->create(['user_id' => $user->id]);
    }

    private function createLoanWithStatus(Account $account, LoanStatus $status): void
    {
        $ignoreStaleSqliteConstraint = DB::getDriverName() === 'sqlite'
            && in_array($status, [LoanStatus::Paid, LoanStatus::Missed], true);

        if ($ignoreStaleSqliteConstraint) {
            DB::statement('PRAGMA ignore_check_constraints = ON');
        }

        try {
            Loan::query()->create([
                'nation_id' => $account->nation_id,
                'account_id' => $account->id,
                'amount' => 1000,
                'remaining_balance' => 1000,
                'status' => $status,
                'pending_key' => $status === LoanStatus::Pending ? 1 : null,
            ]);
        } finally {
            if ($ignoreStaleSqliteConstraint) {
                DB::statement('PRAGMA ignore_check_constraints = OFF');
            }
        }
    }

    private function createPendingMemberTransfer(Account $source, Account $destination, User $user): void
    {
        MemberTransfer::query()->create([
            'from_account_id' => $source->id,
            'to_account_id' => $destination->id,
            'from_nation_id' => $source->nation_id,
            'to_nation_id' => $destination->nation_id,
            'created_by' => $user->id,
            'status' => MemberTransfer::STATUS_PENDING,
            'money' => 10,
        ]);
    }

    private function assertPendingMemberTransferBlocksDeletion(Account $account, int $nationId): void
    {
        try {
            AccountService::deleteAccount($account, $nationId);
            $this->fail('An account with pending member-transfer escrow was deleted.');
        } catch (UserErrorException $exception) {
            $this->assertSame('The account has pending member transfers.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('accounts', ['id' => $account->id]);
    }
}
