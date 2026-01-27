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
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('nation_id')->change();
            $table->unsignedBigInteger('account_id')->change();
        });

        if (! Schema::hasColumn('grant_applications', 'pending_key')) {
            Schema::table('grant_applications', function (Blueprint $table) {
                $table->unsignedTinyInteger('pending_key')->nullable()->after('status');
            });
        }

        $duplicates = DB::table('grant_applications')
            ->select('grant_id', 'nation_id', DB::raw('COUNT(*) as total'))
            ->where('status', 'pending')
            ->groupBy('grant_id', 'nation_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $pendingIds = DB::table('grant_applications')
                ->where('grant_id', $duplicate->grant_id)
                ->where('nation_id', $duplicate->nation_id)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->pluck('id');

            $pendingIds->shift();

            if ($pendingIds->isNotEmpty()) {
                DB::table('grant_applications')
                    ->whereIn('id', $pendingIds->all())
                    ->update([
                        'status' => 'denied',
                        'denied_at' => now(),
                        'pending_key' => null,
                    ]);
            }
        }

        DB::table('grant_applications')
            ->where('status', 'pending')
            ->update(['pending_key' => 1]);

        Schema::table('grant_applications', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->noActionOnDelete();
            $table->unique(['grant_id', 'nation_id', 'pending_key'], 'grant_applications_pending_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grant_applications', function (Blueprint $table) {
            $table->dropUnique('grant_applications_pending_unique');
            $table->dropForeign(['account_id']);
            $table->dropColumn('pending_key');
            $table->unsignedInteger('nation_id')->change();
            $table->unsignedInteger('account_id')->change();
        });
    }
};
