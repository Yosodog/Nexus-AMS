<?php

namespace Tests\Feature\Workflows;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\MemberTransfer;
use App\Models\Nation;
use App\Models\User;
use App\Services\MemberTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class MemberTransferWorkflowTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
    }

    public function test_member_can_request_a_transfer_through_the_controller_path(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778001, resources: ['money' => 500, 'food' => 100]);
        [, , $toAccount] = $this->createMemberWithAccount(778002);

        $this->actingAs($sender)
            ->post(route('accounts.transfer'), [
                'from' => $fromAccount->id,
                'to' => $toAccount->id,
                'money' => 150,
                'food' => 25,
            ])
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'info')
            ->assertSessionHas('alert-message', 'Transfer request sent. Awaiting recipient approval.');

        $fromAccount->refresh();

        $this->assertSame(350.0, (float) $fromAccount->money);
        $this->assertSame(75.0, (float) $fromAccount->food);
        $this->assertDatabaseHas('member_transfers', [
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'status' => MemberTransfer::STATUS_PENDING,
            'money' => 150,
            'food' => 25,
        ]);
    }

    public function test_accepting_a_transfer_deposits_resources_into_the_destination_account(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778003, resources: ['money' => 400]);
        [$recipient, , $toAccount] = $this->createMemberWithAccount(778004, resources: ['money' => 50]);

        $transfer = app(MemberTransferService::class)->requestTransfer($sender, $fromAccount->id, $toAccount->id, [
            'money' => 125,
        ]);

        $this->actingAs($recipient)
            ->post(route('member-transfers.accept', ['memberTransfer' => $transfer->id]))
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success')
            ->assertSessionHas('alert-message', 'Transfer accepted and applied to your account.');

        $transfer->refresh();
        $toAccount->refresh();

        $this->assertSame(MemberTransfer::STATUS_ACCEPTED, $transfer->status);
        $this->assertNotNull($transfer->accepted_at);
        $this->assertSame(175.0, (float) $toAccount->money);
        $this->assertDatabaseHas('transactions', [
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'transaction_type' => 'member_transfer',
            'money' => 125,
            'is_pending' => 0,
        ]);
    }

    public function test_declining_a_transfer_refunds_the_source_account(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778005, resources: ['money' => 300]);
        [$recipient, , $toAccount] = $this->createMemberWithAccount(778006);

        $transfer = app(MemberTransferService::class)->requestTransfer($sender, $fromAccount->id, $toAccount->id, [
            'money' => 90,
        ]);

        $this->actingAs($recipient)
            ->post(route('member-transfers.decline', ['memberTransfer' => $transfer->id]))
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'info')
            ->assertSessionHas('alert-message', 'Transfer declined and refunded to the sender.');

        $transfer->refresh();
        $fromAccount->refresh();

        $this->assertSame(MemberTransfer::STATUS_DECLINED, $transfer->status);
        $this->assertNotNull($transfer->declined_at);
        $this->assertSame(300.0, (float) $fromAccount->money);
    }

    public function test_frozen_source_account_cannot_create_a_member_transfer_request(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778007, resources: ['money' => 300], frozen: true);
        [, , $toAccount] = $this->createMemberWithAccount(778008);

        $this->actingAs($sender)
            ->from(route('accounts'))
            ->post(route('accounts.transfer'), [
                'from' => $fromAccount->id,
                'to' => $toAccount->id,
                'money' => 50,
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('errors', fn ($errors): bool => str_contains($errors->first(), 'This account is frozen. Transfers are disabled.'));
    }

    public function test_frozen_destination_account_cannot_accept_a_pending_transfer(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778009, resources: ['money' => 300]);
        [$recipient, , $toAccount] = $this->createMemberWithAccount(778010, frozen: true);

        $transfer = MemberTransfer::query()->create([
            'from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id,
            'from_nation_id' => $fromAccount->nation_id,
            'to_nation_id' => $toAccount->nation_id,
            'created_by' => $sender->id,
            'status' => MemberTransfer::STATUS_PENDING,
            'money' => 75,
        ]);

        $this->actingAs($recipient)
            ->from(route('accounts'))
            ->post(route('member-transfers.accept', ['memberTransfer' => $transfer->id]))
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('errors', fn ($errors): bool => str_contains($errors->first(), 'The destination account is frozen. Transfers are disabled.'));

        $transfer->refresh();
        $this->assertSame(MemberTransfer::STATUS_PENDING, $transfer->status);
    }

    public function test_same_account_transfers_are_rejected(): void
    {
        [$sender, , $account] = $this->createMemberWithAccount(778011, resources: ['money' => 300]);

        $this->actingAs($sender)
            ->from(route('accounts'))
            ->post(route('accounts.transfer'), [
                'from' => $account->id,
                'to' => $account->id,
                'money' => 10,
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors([
                'to' => 'Cannot transfer resources to the same account.',
            ]);
    }

    public function test_same_nation_member_transfer_requests_are_rejected(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778012, resources: ['money' => 300]);
        [, , $toAccount] = $this->createSecondaryAccountForNation($sender->nation_id);

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('Use internal transfers for your own accounts.');

        app(MemberTransferService::class)->requestTransfer($sender, $fromAccount->id, $toAccount->id, [
            'money' => 50,
        ]);
    }

    public function test_outside_alliance_member_transfer_requests_are_rejected(): void
    {
        [$sender, , $fromAccount] = $this->createMemberWithAccount(778013, resources: ['money' => 300]);
        [, , $toAccount] = $this->createMemberWithAccount(778014, allianceId: 999);

        $this->actingAs($sender)
            ->from(route('accounts'))
            ->post(route('accounts.transfer'), [
                'from' => $fromAccount->id,
                'to' => $toAccount->id,
                'money' => 10,
            ])
            ->assertRedirect(route('accounts'))
            ->assertSessionHasErrors([
                'to' => 'Transfers are only allowed within your alliance.',
            ]);
    }

    /**
     * @param  array<string, float|int>  $resources
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createMemberWithAccount(
        int $nationId,
        int $allianceId = 777,
        array $resources = [],
        bool $frozen = false
    ): array {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => $allianceId,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 1,
        ]);

        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->frozen = $frozen;

        foreach ($resources as $resource => $amount) {
            $account->{$resource} = $amount;
        }

        $account->save();

        return [$user, $nation, $account];
    }

    /**
     * @return array{0: User, 1: Nation, 2: Account}
     */
    private function createSecondaryAccountForNation(int $nationId): array
    {
        $user = User::query()->where('nation_id', $nationId)->firstOrFail();
        $nation = Nation::query()->findOrFail($nationId);
        $account = new Account;
        $account->nation_id = $nationId;
        $account->name = 'Secondary';
        $account->save();

        return [$user, $nation, $account];
    }
}
