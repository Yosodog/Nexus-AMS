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
        if (! Schema::hasColumn('rebuilding_requests', 'pending_key')) {
            Schema::table('rebuilding_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        $duplicates = DB::table('rebuilding_requests')
            ->select('cycle_id', 'nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('cycle_id', 'nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('rebuilding_requests')
                ->where('cycle_id', $duplicate->cycle_id)
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('rebuilding_requests')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'denied',
                        'denied_at' => now(),
                        'pending_key' => null,
                    ]);
            }
        }

        DB::table('rebuilding_requests')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        Schema::table('rebuilding_requests', function (Blueprint $table) {
            $table->unique(['cycle_id', 'nation_id', 'pending_key'], 'rebuilding_requests_pending_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rebuilding_requests', function (Blueprint $table) {
            $table->dropUnique('rebuilding_requests_pending_unique');
            $table->dropColumn('pending_key');
        });
    }
};
