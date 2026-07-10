<?php

namespace Tests\Feature\Migrations;

use App\Models\Account;
use App\Models\Nation;
use App\Models\Transaction;
use App\Services\PWHelperService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WithdrawalReconciliationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_processing_withdrawal_is_backfilled_for_safe_reconciliation(): void
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Legacy withdrawal source';
        $account->save();

        $transaction = TransactionService::createTransaction(
            collect(PWHelperService::resources())
                ->mapWithKeys(fn (string $resource): array => [$resource => $resource === 'money' ? 100 : 0])
                ->all(),
            $nation->id,
            $account->id,
            'withdrawal',
        );
        $processingAt = now()->subMinutes(15)->startOfSecond();
        $transaction->bank_processing_at = $processingAt;
        $transaction->approved_at = $processingAt;
        $transaction->save();

        $migration = require database_path('migrations/2026_07_10_012847_add_withdrawal_reconciliation_to_transactions_table.php');
        $migration->down();
        $migration->up();

        $transaction = Transaction::query()->findOrFail($transaction->id);

        $this->assertSame(Transaction::BANK_ATTEMPT_NEEDS_RECONCILIATION, $transaction->bank_attempt_status);
        $this->assertSame('NXS-WD-LEGACY-'.$transaction->id, $transaction->bank_correlation_id);
        $this->assertSame(1, $transaction->bank_attempt_count);
        $this->assertTrue($transaction->requires_admin_approval);
        $this->assertNull($transaction->bank_processing_at);
        $this->assertTrue($transaction->bank_reconciliation_details['legacy_processing_row']);
        $this->assertFalse($transaction->bank_reconciliation_details['correlation_present_in_bank_note']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resolve all ambiguous withdrawals');

        $migration->down();
    }
}
