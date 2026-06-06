<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIN_SURCHARGE_PCT = 0.00;

    private const MAX_SURCHARGE_PCT = 100.00;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('mmr_settings')) {
            return;
        }

        DB::table('mmr_settings')
            ->where('surcharge_pct', '<', self::MIN_SURCHARGE_PCT)
            ->update(['surcharge_pct' => self::MIN_SURCHARGE_PCT]);

        DB::table('mmr_settings')
            ->where('surcharge_pct', '>', self::MAX_SURCHARGE_PCT)
            ->update(['surcharge_pct' => self::MAX_SURCHARGE_PCT]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
