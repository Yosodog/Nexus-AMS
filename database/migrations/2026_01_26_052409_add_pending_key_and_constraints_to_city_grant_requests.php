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
        Schema::table('city_grant_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('nation_id')->change();
            $table->unsignedBigInteger('account_id')->change();
        });

        if (! Schema::hasColumn('city_grant_requests', 'pending_key')) {
            Schema::table('city_grant_requests', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        $duplicates = DB::table('city_grant_requests')
            ->select('nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('city_grant_requests')
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('city_grant_requests')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'denied',
                        'denied_at' => now(),
                        'pending_key' => null,
                    ]);
            }
        }

        DB::table('city_grant_requests')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        Schema::table('city_grant_requests', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->noActionOnDelete();
            $table->unique(['nation_id', 'pending_key'], 'city_grant_requests_pending_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('city_grant_requests', function (Blueprint $table) {
            $table->dropUnique('city_grant_requests_pending_unique');
            $table->dropForeign(['account_id']);
            $table->dropColumn('pending_key');
            $table->unsignedInteger('nation_id')->change();
            $table->unsignedInteger('account_id')->change();
        });
    }
};
