<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'transactions_payroll_idempotency_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'payroll_idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('payroll_idempotency_key')->nullable()->after('payroll_run_date');
            });
        }

        $seen = [];

        DB::table('transactions')
            ->where('transaction_type', 'payroll')
            ->whereNotNull('payroll_member_id')
            ->whereNotNull('payroll_run_date')
            ->whereNull('payroll_idempotency_key')
            ->orderBy('id')
            ->get(['id', 'payroll_member_id', 'payroll_run_date'])
            ->each(function (object $transaction) use (&$seen): void {
                $canonicalKey = $this->payrollIdempotencyKey(
                    (int) $transaction->payroll_member_id,
                    (string) $transaction->payroll_run_date
                );

                $idempotencyKey = isset($seen[$canonicalKey])
                    ? "{$canonicalKey}:legacy:{$transaction->id}"
                    : $canonicalKey;

                $seen[$canonicalKey] = true;

                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->update(['payroll_idempotency_key' => $idempotencyKey]);
            });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('payroll_idempotency_key', self::INDEX_NAME);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
            $table->dropColumn('payroll_idempotency_key');
        });
    }

    private function payrollIdempotencyKey(int $payrollMemberId, string $runDate): string
    {
        return 'payroll:'.$payrollMemberId.':'.Carbon::parse($runDate)->toDateString();
    }
};
