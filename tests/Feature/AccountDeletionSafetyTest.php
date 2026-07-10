<?php

namespace Tests\Feature;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
