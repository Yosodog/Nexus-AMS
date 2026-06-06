<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_KEY_VALUE = 1;

    private const INDEX_NAME = 'war_counter_open_unique';

    /**
     * @return array<int, string>
     */
    private function openStatuses(): array
    {
        return ['draft', 'active'];
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('war_counters', 'active_key')) {
            Schema::table('war_counters', function (Blueprint $table) {
                $table->unsignedTinyInteger('active_key')->nullable()->after('status');
            });
        }

        $now = now();

        DB::table('war_counters')
            ->select('aggressor_nation_id')
            ->whereIn('status', $this->openStatuses())
            ->groupBy('aggressor_nation_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('aggressor_nation_id')
            ->each(function (int $aggressorNationId) use ($now): void {
                $openCounters = DB::table('war_counters')
                    ->where('aggressor_nation_id', $aggressorNationId)
                    ->whereIn('status', $this->openStatuses())
                    ->orderByDesc('last_war_declared_at')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->pluck('id');

                $idsToArchive = $openCounters->slice(1)->values();

                if ($idsToArchive->isEmpty()) {
                    return;
                }

                DB::table('war_counters')
                    ->whereIn('id', $idsToArchive->all())
                    ->update([
                        'status' => 'archived',
                        'active_key' => null,
                        'archived_at' => $now,
                        'updated_at' => $now,
                    ]);
            });

        DB::table('war_counters')
            ->whereIn('status', $this->openStatuses())
            ->update(['active_key' => self::ACTIVE_KEY_VALUE]);

        DB::table('war_counters')
            ->whereNotIn('status', $this->openStatuses())
            ->update(['active_key' => null]);

        Schema::table('war_counters', function (Blueprint $table) {
            $table->unique(['aggressor_nation_id', 'active_key'], self::INDEX_NAME);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_counters', function (Blueprint $table) {
            $table->dropUnique(self::INDEX_NAME);
            $table->dropColumn('active_key');
        });
    }
};
