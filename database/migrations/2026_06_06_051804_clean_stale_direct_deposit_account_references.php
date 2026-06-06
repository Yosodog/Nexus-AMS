<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        if (Schema::hasTable('direct_deposit_enrollments')) {
            $staleEnrollmentIds = DB::table('direct_deposit_enrollments as enrollments')
                ->leftJoin('accounts', 'accounts.id', '=', 'enrollments.account_id')
                ->where(function ($query): void {
                    $query->whereNull('accounts.id')
                        ->orWhereNotNull('accounts.deleted_at')
                        ->orWhere('accounts.frozen', true)
                        ->orWhereColumn('accounts.nation_id', '!=', 'enrollments.nation_id');
                })
                ->pluck('enrollments.id');

            if ($staleEnrollmentIds->isNotEmpty()) {
                DB::table('direct_deposit_enrollments')
                    ->whereIn('id', $staleEnrollmentIds->all())
                    ->delete();
            }
        }

        if (Schema::hasTable('mmr_configs')) {
            $staleConfigIds = DB::table('mmr_configs')
                ->leftJoin('accounts', 'accounts.id', '=', 'mmr_configs.account_id')
                ->where(function ($query): void {
                    $query->whereNull('accounts.id')
                        ->orWhereNotNull('accounts.deleted_at')
                        ->orWhere('accounts.frozen', true)
                        ->orWhereColumn('accounts.nation_id', '!=', 'mmr_configs.nation_id');
                })
                ->pluck('mmr_configs.id');

            if ($staleConfigIds->isNotEmpty()) {
                DB::table('mmr_configs')
                    ->whereIn('id', $staleConfigIds->all())
                    ->update([
                        'enabled' => false,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
