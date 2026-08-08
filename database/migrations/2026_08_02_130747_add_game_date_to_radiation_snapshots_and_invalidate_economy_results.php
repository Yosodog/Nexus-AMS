<?php

use App\Support\Database\WorldSchema;
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
        if (! Schema::hasColumn('radiation_snapshots', 'game_date')) {
            WorldSchema::table('radiation_snapshots', function (Blueprint $table) {
                $table->date('game_date')->nullable()->after('snapshot_at');
            });
        }

        DB::transaction(function (): void {
            DB::table('nation_profitability_snapshots')
                ->where('model_version', 2)
                ->update(['model_version' => 1]);
            DB::table('nation_build_recommendations')
                ->where('model_version', 2)
                ->update(['model_version' => 1]);
        });
    }

    /**
     * Reverse only the schema change. Version invalidation is intentionally irreversible because
     * corrected and legacy rows cannot be distinguished after version 2 has been repopulated.
     */
    public function down(): void
    {
        WorldSchema::table('radiation_snapshots', function (Blueprint $table) {
            $table->dropColumn('game_date');
        });
    }
};
