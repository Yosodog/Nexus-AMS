<?php

namespace Tests\Feature\Workflows;

use App\GraphQL\Models\BankRecord;
use App\Jobs\SendBank;
use App\Models\Account;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BankService;
use App\Services\OffshoreFulfillmentResult;
use App\Services\OffshoreFulfillmentService;
use App\Services\PWHelperService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class WithdrawalRefundRaceTest extends TestCase
{
    use BuildsTestUsers;
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_refund_rejects_processing_withdrawal_without_crediting_account(): void
    {
        [$admin, $account, $transaction] = $this->createWithdrawalFixture();
        $transaction->bank_processing_at = now();
        $transaction->save();

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.accounts.transactions.refund', $transaction))
            ->assertRedirect('/admin/accounts')
            ->assertSessionHas('alert-type', 'error');

        $transaction->refresh();

        $this->assertTrue($transaction->is_pending);
        $this->assertNull($transaction->refunded_at);
        $this->assertNotNull($transaction->bank_processing_at);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
    }

    public function test_unstuck_refund_rejects_processing_withdrawal_without_crediting_account(): void
    {
        [$admin, $account, $transaction] = $this->createWithdrawalFixture();
        $transaction->bank_processing_at = now();
        $transaction->save();

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.accounts.transactions.unstuck_refund', $transaction))
            ->assertRedirect('/admin/accounts')
            ->assertSessionHas('alert-type', 'error');

        $transaction->refresh();

        $this->assertTrue($transaction->is_pending);
        $this->assertNull($transaction->refunded_at);
        $this->assertNotNull($transaction->bank_processing_at);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
    }

    public function test_send_bank_does_not_mark_refunded_transaction_sent_after_external_send(): void
    {
        [, , $transaction] = $this->createWithdrawalFixture();
        $fulfillment = Mockery::mock(OffshoreFulfillmentService::class);
        $fulfillment->shouldReceive('coverShortfall')
            ->once()
            ->andReturn(new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_SKIPPED,
                'No offshore fulfillment required.'
            ));

        $bankRecord = $this->bankRecordFor($transaction);
        $bank = Mockery::mock(BankService::class);
        $bank->shouldReceive('sendWithdraw')
            ->once()
            ->andReturnUsing(function () use ($transaction, $bankRecord): BankRecord {
                $refunded = Transaction::query()->findOrFail($transaction->id);
                $refunded->is_pending = false;
                $refunded->refunded_at = now();
                $refunded->bank_processing_at = null;
                $refunded->save();

                return $bankRecord;
            });

        (new SendBank($bank, $transaction))->handle($fulfillment);

        $transaction->refresh();

        $this->assertFalse($transaction->is_pending);
        $this->assertNotNull($transaction->refunded_at);
        $this->assertNull($transaction->sent_at);
        $this->assertNull($transaction->bank_record_id);
    }

    /**
     * @return array{0: User, 1: Account, 2: Transaction}
     */
    private function createWithdrawalFixture(): array
    {
        $nation = Nation::factory()->create();
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $nation->id + 100000]),
            ['manage-accounts']
        );
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Withdrawal source';
        $account->money = 0;
        $account->save();

        $transaction = TransactionService::createTransaction(
            $this->resourcePayload(['money' => 100]),
            $nation->id,
            $account->id,
            'withdrawal'
        );

        return [$admin, $account, $transaction];
    }

    /**
     * @param  array<string, float|int>  $overrides
     * @return array<string, float|int>
     */
    private function resourcePayload(array $overrides = []): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => $overrides[$resource] ?? 0])
            ->all();
    }

    private function bankRecordFor(Transaction $transaction): BankRecord
    {
        $bankRecord = new BankRecord;
        $bankRecord->buildWithJSON((object) [
            'id' => 987654,
            'date' => now()->toISOString(),
            'sender_id' => 1,
            'sender_type' => 2,
            'receiver_id' => $transaction->nation_id,
            'receiver_type' => 1,
            'banker_id' => 1,
            'note' => 'Withdrawal',
            'money' => (float) $transaction->money,
        ]);

        return $bankRecord;
    }
}
