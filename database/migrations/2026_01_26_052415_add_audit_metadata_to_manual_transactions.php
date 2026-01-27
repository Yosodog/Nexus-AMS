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
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('grant_application_id')->nullable()->after('admin_id');
            $table->unsignedBigInteger('city_grant_request_id')->nullable()->after('grant_application_id');
            $table->string('correlation_id', 36)->nullable()->after('city_grant_request_id');
            $table->json('meta')->nullable()->after('ip_address');
        });

        $duplicateGrantIds = DB::table('manual_transactions')
            ->select('grant_application_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('grant_application_id')
            ->groupBy('grant_application_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicateGrantIds as $duplicate) {
            $ids = DB::table('manual_transactions')
                ->where('grant_application_id', $duplicate->grant_application_id)
                ->orderBy('id')
                ->pluck('id');

            $ids->shift();

            if ($ids->isNotEmpty()) {
                DB::table('manual_transactions')
                    ->whereIn('id', $ids->all())
                    ->update(['grant_application_id' => null]);
            }
        }

        $duplicateCityIds = DB::table('manual_transactions')
            ->select('city_grant_request_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('city_grant_request_id')
            ->groupBy('city_grant_request_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicateCityIds as $duplicate) {
            $ids = DB::table('manual_transactions')
                ->where('city_grant_request_id', $duplicate->city_grant_request_id)
                ->orderBy('id')
                ->pluck('id');

            $ids->shift();

            if ($ids->isNotEmpty()) {
                DB::table('manual_transactions')
                    ->whereIn('id', $ids->all())
                    ->update(['city_grant_request_id' => null]);
            }
        }

        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->foreign('grant_application_id')
                ->references('id')
                ->on('grant_applications')
                ->nullOnDelete();
            $table->foreign('city_grant_request_id')
                ->references('id')
                ->on('city_grant_requests')
                ->nullOnDelete();
            $table->unique('grant_application_id', 'manual_transactions_grant_application_unique');
            $table->unique('city_grant_request_id', 'manual_transactions_city_grant_request_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->dropUnique('manual_transactions_grant_application_unique');
            $table->dropUnique('manual_transactions_city_grant_request_unique');
            $table->dropForeign(['grant_application_id']);
            $table->dropForeign(['city_grant_request_id']);
            $table->dropColumn([
                'grant_application_id',
                'city_grant_request_id',
                'correlation_id',
                'meta',
            ]);
        });
    }
};
