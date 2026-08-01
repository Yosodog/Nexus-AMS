<?php

namespace Tests\Feature\Migrations;

use App\Models\Account;
use App\Models\Nation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PWHelperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class LegacyPendingGuardAutoRefundMigrationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    private const LEGACY_PENDING_REASON = 'Auto-refunded during pending withdrawal guard migration because another pending withdrawal already existed for this nation.';

    public function test_forward_migration_quarantines_legacy_row_without_changing_balances_or_refunding_again(): void
    {
        [, $account, $transaction] = $this->createLegacyAutoRefundFixture();
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $transaction->refresh();

        $this->assertFalse($transaction->is_pending);
        $this->assertTrue($transaction->requires_admin_approval);
        $this->assertSame(Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION, $transaction->bank_attempt_status);
        $this->assertSame('NXS-WD-LEGACY-'.$transaction->id, $transaction->bank_correlation_id);
        $this->assertNotNull($transaction->refunded_at);
        $this->assertNull($transaction->pending_withdrawal_key);
        $this->assertTrue($transaction->hasLegacyPendingGuardAutoRefund());
        $this->assertSame('unknown', $transaction->bank_reconciliation_details['legacy_pending_guard_auto_refund']['external_bank_outcome']);
        $this->assertSame('requires_verification', $transaction->bank_reconciliation_details['legacy_pending_guard_auto_refund']['local_credit_outcome']);
        $this->assertSame('100.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('25.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resolve all legacy pending-guard auto-refunds');

        $migration->down();
    }

    public function test_reconciliation_requires_an_explicit_legacy_local_credit_outcome(): void
    {
        [$admin, , $transaction] = $this->createLegacyAutoRefundFixture();
        $this->migration()->up();

        $this->actingAs($admin)
            ->from(route('admin.accounts.dashboard'))
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'evidence' => 'Complete alliance bank history shows no matching transfer or resource amounts.',
            ])
            ->assertRedirect(route('admin.accounts.dashboard'))
            ->assertSessionHasErrors('legacy_refund_credit_status');

        $this->assertTrue($transaction->fresh()->requiresBankReconciliation());
    }

    public function test_confirmed_existing_credit_is_preserved_without_a_duplicate_refund(): void
    {
        [$admin, $account, $transaction] = $this->createLegacyAutoRefundFixture();
        $this->migration()->up();
        $evidence = 'Complete alliance bank history shows no matching transfer or resource amounts.';

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'legacy_refund_credit_status' => 'confirmed_applied',
                'evidence' => $evidence,
            ])
            ->assertSessionHas('alert-type', 'success');

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'legacy_refund_credit_status' => 'confirmed_applied',
                'evidence' => $evidence,
            ])
            ->assertSessionHas('alert-type', 'error');

        $transaction->refresh();

        $this->assertSame(Transaction::BANK_ATTEMPT_RECONCILED_REFUNDED, $transaction->bank_attempt_status);
        $this->assertSame('existing_legacy_credit_preserved', $transaction->bank_reconciliation_details['legacy_pending_guard_auto_refund']['local_balance_action']);
        $this->assertSame('100.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('25.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_reconciled_refunded',
            'subject_id' => (string) $transaction->id,
        ]);
    }

    public function test_confirmed_missing_credit_is_applied_once_after_external_non_send_is_verified(): void
    {
        [$admin, $account, $transaction] = $this->createLegacyAutoRefundFixture();
        $account->money = 0;
        $account->coal = 0;
        $account->save();
        $this->migration()->up();

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_not_sent',
                'legacy_refund_credit_status' => 'confirmed_not_applied',
                'evidence' => 'Bank history has no transfer and the account ledger confirms the migration credit never landed.',
            ])
            ->assertSessionHas('alert-type', 'success');

        $transaction->refresh();

        $this->assertSame(Transaction::BANK_ATTEMPT_RECONCILED_REFUNDED, $transaction->bank_attempt_status);
        $this->assertSame('missing_legacy_credit_applied', $transaction->bank_reconciliation_details['legacy_pending_guard_auto_refund']['local_balance_action']);
        $this->assertSame('100.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('25.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 1);
    }

    public function test_confirmed_external_send_reverses_a_verified_legacy_credit_once(): void
    {
        [$admin, $account, $transaction] = $this->createLegacyAutoRefundFixture();
        $this->migration()->up();
        $evidence = 'Alliance bank record 987654 matches the recipient and every resource amount.';

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_sent',
                'legacy_refund_credit_status' => 'confirmed_applied',
                'bank_record_id' => 987654,
                'evidence' => $evidence,
            ])
            ->assertSessionHas('alert-type', 'success');

        $this->actingAs($admin)
            ->post(route('admin.withdrawals.reconcile', $transaction), [
                'resolution' => 'confirmed_sent',
                'legacy_refund_credit_status' => 'confirmed_applied',
                'bank_record_id' => 987654,
                'evidence' => $evidence,
            ])
            ->assertSessionHas('alert-type', 'error');

        $transaction->refresh();

        $this->assertSame(Transaction::BANK_ATTEMPT_RECONCILED_SENT, $transaction->bank_attempt_status);
        $this->assertSame(987654, $transaction->bank_record_id);
        $this->assertNull($transaction->refunded_at);
        $this->assertSame('legacy_credit_reversed', $transaction->bank_reconciliation_details['legacy_pending_guard_auto_refund']['local_balance_action']);
        $this->assertSame('0.00', number_format((float) $account->fresh()->money, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
        $this->assertDatabaseCount('manual_transactions', 1);
        $this->assertDatabaseHas('manual_transactions', [
            'account_id' => $account->id,
            'money' => -100,
            'coal' => -25,
            'correlation_id' => $transaction->bank_correlation_id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'withdrawal_reconciled_sent',
            'subject_id' => (string) $transaction->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Account, 2: Transaction}
     */
    private function createLegacyAutoRefundFixture(): array
    {
        $nation = Nation::factory()->create();
        $admin = $this->grantPermissions(
            $this->createVerifiedAdmin(['nation_id' => $nation->id + 100000]),
            ['manage-accounts', 'view-accounts', 'view-diagnostic-info']
        );
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Legacy migration refund';
        $account->money = 100;
        $account->coal = 25;
        $account->save();

        $transaction = new Transaction;
        $transaction->from_account_id = $account->id;
        $transaction->nation_id = $nation->id;
        $transaction->transaction_type = 'withdrawal';
        $transaction->money = 100;
        $transaction->coal = 25;
        $transaction->is_pending = false;
        $transaction->requires_admin_approval = false;
        $transaction->refunded_at = now()->subMonth();
        $transaction->pending_reason = self::LEGACY_PENDING_REASON;

        foreach (PWHelperService::resources() as $resource) {
            $transaction->{$resource} = 0;
        }

        $transaction->money = 100;
        $transaction->coal = 25;

        $transaction->save();

        return [$admin, $account, $transaction];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_01_210000_quarantine_legacy_auto_refunded_withdrawals.php');
    }
}
