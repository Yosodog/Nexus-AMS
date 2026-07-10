<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $duplicateRecordIds = DB::table('transactions')
            ->select('bank_record_id')
            ->whereNotNull('bank_record_id')
            ->groupBy('bank_record_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('bank_record_id')
            ->limit(10)
            ->pluck('bank_record_id');

        if ($duplicateRecordIds->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot enforce unique withdrawal bank records. Resolve duplicate record IDs first: %s',
                $duplicateRecordIds->implode(', ')
            ));
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('bank_record_id', 'transactions_bank_record_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_bank_record_id_unique');
        });
    }
};
