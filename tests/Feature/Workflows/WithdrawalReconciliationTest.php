<?php

namespace Tests\Feature\Workflows;

use App\Exceptions\AmbiguousMutationOutcomeException;
use App\Exceptions\DefiniteMutationFailureException;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class WithdrawalReconciliationTest extends TestCase
{
    use BuildsTestUsers;
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_ambiguous_send_is_not_retried_approved_denied_or_refunded(): void
    {
        Notification::fake();
        [$admin, $account, $transaction] = $this->createWithdrawalFixture(manuallyApproved: true);
        $fulfillment = $this->fulfillmentService();
        $bank = Mockery::mock(BankService::class);
        $bank->note = 'Member withdrawal';
        $bank->shouldReceive('sendWithdraw')
            ->once()
            ->andThrow(new AmbiguousMutationOutcomeException('Response timed out after dispatch.'));

        $job = new SendBank($bank, $transaction);
        $job->handle($fulfillment);
        $job->handle($fulfillment);

        $transaction->refresh();

        $this->assertTrue($transaction->is_pending);
        $this->assertTrue($transaction->requires_admin_approval);
        $this->assertTrue($transaction->requiresBankReconciliation());
        $this->assertSame('NXS-WD-'.$transaction->id, $transaction->bank_correlation_id);
        $this->assertSame(1, $transaction->bank_attempt_count);
        $this->assertNotNull($transaction->bank_attempted_at);
        $this->assertNull($transaction->bank_processing_at);
        $this->assertNotNull($transaction->approved_at);
        $this->assertStringEndsWith('['.$transaction->bank_correlation_id.']', $bank->note);

        Queue::fake();

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.withdrawals.approve', $transaction))
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'error');

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.withdrawals.deny', $transaction), ['reason' => 'Return it'])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'error');

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.accounts.transactions.refund', $transaction))
            ->assertRedirect('/admin/accounts')
            ->assertSessionHas('alert-type', 'error');

        $this->actingAs($admin)
            ->from('/admin/accounts')
            ->post(route('admin.accounts.transactions.unstuck_refund', $transaction))
            ->assertRedirect('/admin/accounts')
            ->assertSessionHas('alert-type', 'error');

        Queue::assertNotPushed(SendBank::class);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_reconciliation_required',
            'subject_id' => (string) $transaction->id,
        ]);
    }

    public function test_admin_dashboard_exposes_approved_ambiguous_withdrawal_and_requires_evidence(): void
    {
        [$admin, , $transaction] = $this->createWithdrawalFixture(manuallyApproved: true);
        $this->makeAmbiguous($transaction);
        Cache::put('offshores:main:balances', ['balances' => [], 'cached_at' => now()], now()->addHour());

        $this->actingAs($admin)
            ->get(route('admin.accounts.dashboard'))
            ->assertOk()
            ->assertSee('Withdrawal Reconciliation Required')
            ->assertSee($transaction->fresh()->bank_correlation_id)
            ->assertSee('Record as Sent')
            ->assertSee('Refund After Verification');

        $this->actingAs($admin)
            ->from(route('admin.accounts.dashboard'))
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_sent',
                'bank_record_id' => 876543,
            ])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHasErrors('evidence');

        $this->assertTrue($transaction->fresh()->requiresBankReconciliation());
    }

    public function test_evidence_can_reconcile_ambiguous_withdrawal_as_sent(): void
    {
        [$admin, $account, $transaction] = $this->createWithdrawalFixture(manuallyApproved: true);
        $this->makeAmbiguous($transaction);

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_sent',
                'bank_record_id' => 876543,
                'evidence' => 'Alliance bank record 876543 matches the correlation ID and every resource amount.',
            ])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'success');

        $transaction->refresh();

        $this->assertFalse($transaction->is_pending);
        $this->assertSame(Transaction::BANK_ATTEMPT_RECONCILED_SENT, $transaction->bank_attempt_status);
        $this->assertSame(876543, $transaction->bank_record_id);
        $this->assertNotNull($transaction->sent_at);
        $this->assertNull($transaction->refunded_at);
        $this->assertNull($transaction->pending_withdrawal_key);
        $this->assertSame('confirmed_sent', $transaction->bank_reconciliation_details['resolution']);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_reconciled_sent',
            'subject_id' => (string) $transaction->id,
        ]);
    }

    public function test_evidence_can_reconcile_ambiguous_withdrawal_as_not_sent_and_refund_once(): void
    {
        [$admin, $account, $transaction] = $this->createWithdrawalFixture(manuallyApproved: true);
        $this->makeAmbiguous($transaction);
        $evidence = 'Reviewed the complete alliance bank history around the attempt; no matching correlation or amounts exist.';

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'evidence' => $evidence,
            ])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'success');

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'evidence' => $evidence,
            ])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'error');

        $transaction->refresh();

        $this->assertFalse($transaction->is_pending);
        $this->assertSame(Transaction::BANK_ATTEMPT_RECONCILED_REFUNDED, $transaction->bank_attempt_status);
        $this->assertNotNull($transaction->refunded_at);
        $this->assertNull($transaction->sent_at);
        $this->assertNull($transaction->pending_withdrawal_key);
        $this->assertSame('100.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_reconciled_refunded',
            'subject_id' => (string) $transaction->id,
        ]);
    }

    public function test_normal_success_records_attempt_and_correlation(): void
    {
        Notification::fake();
        [, , $transaction] = $this->createWithdrawalFixture();
        $fulfillment = $this->fulfillmentService();
        $bank = Mockery::mock(BankService::class);
        $bank->note = str_repeat('Long withdrawal note ', 30);
        $bank->shouldReceive('sendWithdraw')
            ->once()
            ->andReturn($this->bankRecordFor($transaction));

        (new SendBank($bank, $transaction))->handle($fulfillment);

        $transaction->refresh();

        $this->assertFalse($transaction->is_pending);
        $this->assertSame(Transaction::BANK_ATTEMPT_SUCCEEDED, $transaction->bank_attempt_status);
        $this->assertSame(1, $transaction->bank_attempt_count);
        $this->assertNotNull($transaction->bank_attempted_at);
        $this->assertNotNull($transaction->sent_at);
        $this->assertSame(987654, $transaction->bank_record_id);
        $this->assertSame('NXS-WD-'.$transaction->id, $transaction->bank_correlation_id);
        $this->assertLessThanOrEqual(255, mb_strlen($bank->note));
        $this->assertStringEndsWith('['.$transaction->bank_correlation_id.']', $bank->note);
    }

    public function test_definite_pre_send_failure_returns_to_review_and_can_be_approved_again(): void
    {
        [$admin, , $transaction] = $this->createWithdrawalFixture(manuallyApproved: true);
        $fulfillment = $this->fulfillmentService();
        $bank = Mockery::mock(BankService::class);
        $bank->note = 'Member withdrawal';
        $bank->shouldReceive('sendWithdraw')
            ->once()
            ->andThrow(new DefiniteMutationFailureException('Mutation credentials are not configured.'));

        (new SendBank($bank, $transaction))->handle($fulfillment);

        $transaction->refresh();

        $this->assertTrue($transaction->is_pending);
        $this->assertTrue($transaction->requires_admin_approval);
        $this->assertSame(Transaction::BANK_ATTEMPT_FAILED, $transaction->bank_attempt_status);
        $this->assertNull($transaction->bank_processing_at);
        $this->assertNull($transaction->approved_at);
        $this->assertNull($transaction->approved_by);

        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.approve', $transaction))
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHas('alert-type', 'success');

        Queue::assertPushed(SendBank::class, fn (SendBank $job): bool => $job->uniqueId() === 'withdrawal-'.$transaction->id);
    }

    /**
     * @return array{0: User, 1: Account, 2: Transaction}
     */
    private function createWithdrawalFixture(bool $manuallyApproved = false): array
    {
        $nation = Nation::factory()->create();
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $nation->id + 100000]),
            ['manage-accounts', 'view-accounts']
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
            'withdrawal',
            note: 'Member withdrawal',
        );

        if ($manuallyApproved) {
            $transaction->approved_at = now();
            $transaction->approved_by = $admin->id;
            $transaction->save();
        }

        return [$admin, $account, $transaction];
    }

    private function makeAmbiguous(Transaction $transaction): void
    {
        $fulfillment = $this->fulfillmentService();
        $bank = Mockery::mock(BankService::class);
        $bank->note = 'Member withdrawal';
        $bank->shouldReceive('sendWithdraw')
            ->once()
            ->andThrow(new AmbiguousMutationOutcomeException('Response timed out after dispatch.'));

        (new SendBank($bank, $transaction))->handle($fulfillment);
    }

    private function fulfillmentService(): OffshoreFulfillmentService
    {
        $fulfillment = Mockery::mock(OffshoreFulfillmentService::class);
        $fulfillment->shouldReceive('coverShortfall')
            ->once()
            ->andReturn(new OffshoreFulfillmentResult(
                OffshoreFulfillmentResult::STATUS_SKIPPED,
                'No offshore fulfillment required.'
            ));

        return $fulfillment;
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
