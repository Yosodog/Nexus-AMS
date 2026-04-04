<?php

namespace Tests\Feature\Workflows;

use App\Models\Account;
use App\Models\DepositRequest;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class AccountPendingWorkflowTest extends TestCase
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

    public function test_deposit_request_api_reuses_the_existing_pending_code(): void
    {
        [$user, $account] = $this->createVerifiedMemberWithDiscordAndAccount();
        $this->actingAsSanctum($user);

        $firstResponse = $this->postJson("/api/v1/accounts/{$account->id}/deposit-request");
        $secondResponse = $this->postJson("/api/v1/accounts/{$account->id}/deposit-request");

        $firstResponse->assertOk()->assertJson([
            'message' => 'Deposit request created successfully.',
        ]);
        $secondResponse->assertOk()->assertJson([
            'message' => 'You already have a pending deposit request for this account.',
        ]);

        $firstCode = (string) $firstResponse->json('deposit_code');
        $secondCode = (string) $secondResponse->json('deposit_code');

        $this->assertNotSame('', $firstCode);
        $this->assertSame($firstCode, $secondCode);
        $this->assertSame(1, DepositRequest::query()->count());

        $this->assertDatabaseHas('deposit_requests', [
            'account_id' => $account->id,
            'deposit_code' => $firstCode,
            'status' => 'pending',
            'pending_key' => 1,
        ]);
    }

    public function test_deposit_request_api_rejects_an_account_owned_by_another_nation(): void
    {
        [$user] = $this->createVerifiedMemberWithDiscordAndAccount(777801);
        [, $foreignAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777802);
        $this->actingAsSanctum($user);

        $this->postJson("/api/v1/accounts/{$foreignAccount->id}/deposit-request")
            ->assertForbidden()
            ->assertJson([
                'error' => 'Unauthorized',
            ]);

        $this->assertSame(0, DepositRequest::query()->count());
    }

    public function test_member_can_cancel_a_pending_member_transfer_and_receive_a_refund(): void
    {
        [$user, $fromAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777803, money: 400);
        [, $toAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777804);
        $transfer = $this->createPendingTransfer($user, $fromAccount, $toAccount, 100);

        $this->actingAs($user)
            ->from('/')
            ->post(route('member-transfers.cancel', ['memberTransfer' => $transfer->id]))
            ->assertRedirect('/')
            ->assertSessionHas('alert-type', 'info');

        $transfer->refresh();
        $fromAccount->refresh();

        $this->assertSame(MemberTransfer::STATUS_CANCELED, $transfer->status);
        $this->assertNotNull($transfer->canceled_at);
        $this->assertSame($user->id, $transfer->canceled_by);
        $this->assertSame('500.00', number_format((float) $fromAccount->money, 2, '.', ''));
    }

    public function test_non_owner_cannot_cancel_another_users_pending_member_transfer(): void
    {
        [$owner, $fromAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777805, money: 400);
        [, $toAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777806);
        [$otherUser] = $this->createVerifiedMemberWithDiscordAndAccount(777807);
        $transfer = $this->createPendingTransfer($owner, $fromAccount, $toAccount, 100);

        $this->actingAs($otherUser)
            ->from('/')
            ->post(route('member-transfers.cancel', ['memberTransfer' => $transfer->id]))
            ->assertRedirect('/')
            ->assertSessionHas('alert-type', 'error')
            ->assertSessionHasErrors('0');

        $transfer->refresh();
        $fromAccount->refresh();

        $this->assertSame(MemberTransfer::STATUS_PENDING, $transfer->status);
        $this->assertSame('400.00', number_format((float) $fromAccount->money, 2, '.', ''));
    }

    public function test_admin_can_cancel_a_pending_member_transfer_and_refund_the_source_account(): void
    {
        [$owner, $fromAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777808, money: 400);
        [, $toAccount] = $this->createVerifiedMemberWithDiscordAndAccount(777809);
        $transfer = $this->createPendingTransfer($owner, $fromAccount, $toAccount, 100);
        [$admin] = $this->createAdminWithPermission('manage-accounts');

        $this->actingAs($admin)
            ->from('/')
            ->post(route('admin.member-transfers.cancel', ['memberTransfer' => $transfer->id]))
            ->assertRedirect('/')
            ->assertSessionHas('alert-type', 'info');

        $transfer->refresh();
        $fromAccount->refresh();

        $this->assertSame(MemberTransfer::STATUS_CANCELED, $transfer->status);
        $this->assertSame($admin->id, $transfer->canceled_by);
        $this->assertSame('500.00', number_format((float) $fromAccount->money, 2, '.', ''));
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createVerifiedMemberWithDiscordAndAccount(int $nationId = 777800, int $money = 0): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $this->attachDiscordAccount($user);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->money = $money;
        $account->save();

        return [$user, $account];
    }

    /**
     * @return array{0: User, 1: Nation}
     */
    private function createAdminWithPermission(string $permission): array
    {
        $nation = Nation::factory()->create([
            'id' => 777899,
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $admin = User::factory()->verified()->admin()->create([
            'nation_id' => $nation->id,
        ]);

        $this->attachDiscordAccount($admin);
        $admin = $this->grantPermissions($admin, [$permission]);

        return [$admin, $nation];
    }

    private function createPendingTransfer(User $user, Account $fromAccount, Account $toAccount, int $money): MemberTransfer
    {
        return MemberTransfer::query()->create([
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'from_nation_id' => $fromAccount->nation_id,
            'to_nation_id' => $toAccount->nation_id,
            'created_by' => $user->id,
            'status' => MemberTransfer::STATUS_PENDING,
            'money' => $money,
        ]);
    }
}
