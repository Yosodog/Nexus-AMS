<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIN_ADJUSTMENT_PERCENT = -99.99;

    private const MAX_ADJUSTMENT_PERCENT = 100.00;

    private const MAX_BUY_CAP_REMAINING = 100_000_000.00;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('market_resources')) {
            return;
        }

        DB::table('market_resources')
            ->where('adjustment_percent', '<', self::MIN_ADJUSTMENT_PERCENT)
            ->update(['adjustment_percent' => self::MIN_ADJUSTMENT_PERCENT]);

        DB::table('market_resources')
            ->where('adjustment_percent', '>', self::MAX_ADJUSTMENT_PERCENT)
            ->update(['adjustment_percent' => self::MAX_ADJUSTMENT_PERCENT]);

        DB::table('market_resources')
            ->where('buy_cap_remaining', '<', 0)
            ->update(['buy_cap_remaining' => 0]);

        DB::table('market_resources')
            ->where('buy_cap_remaining', '>', self::MAX_BUY_CAP_REMAINING)
            ->update(['buy_cap_remaining' => self::MAX_BUY_CAP_REMAINING]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
