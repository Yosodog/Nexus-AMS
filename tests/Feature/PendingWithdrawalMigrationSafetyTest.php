<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Nation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingWithdrawalMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_pending_withdrawal_is_quarantined_without_refunding_its_account(): void
    {
        $nation = Nation::factory()->create();
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Migration Safety';
        $account->coal = 100;
        $account->save();

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_pending_withdrawal_unique');
            $table->dropColumn('pending_withdrawal_key');
        });

        $base = [
            'from_account_id' => $account->id,
            'to_account_id' => null,
            'nation_id' => $nation->id,
            'transaction_type' => 'withdrawal',
            'coal' => 25,
            'is_pending' => true,
            'requires_admin_approval' => false,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ];
        DB::table('transactions')->insert($base);
        $ambiguousId = DB::table('transactions')->insertGetId([
            ...$base,
            'bank_processing_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_06_06_053031_add_pending_withdrawal_key_to_transactions_table.php');
        $migration->up();

        $ambiguous = DB::table('transactions')->find($ambiguousId);
        $this->assertTrue((bool) $ambiguous->is_pending);
        $this->assertTrue((bool) $ambiguous->requires_admin_approval);
        $this->assertSame('needs_reconciliation', $ambiguous->bank_attempt_status);
        $this->assertNull($ambiguous->pending_withdrawal_key);
        $this->assertNotNull($ambiguous->bank_processing_at);
        $this->assertSame('100.00', number_format((float) $account->fresh()->coal, 2, '.', ''));
    }
}
