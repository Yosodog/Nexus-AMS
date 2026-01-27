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
        $constraint = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND CONSTRAINT_NAME = ?',
            ['city_grant_requests', 'nation_id', 'city_grant_requests_nation_id_foreign']
        );

        if ($constraint) {
            Schema::table('city_grant_requests', function (Blueprint $table) {
                $table->dropForeign('city_grant_requests_nation_id_foreign');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $constraint = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND CONSTRAINT_NAME = ?',
            ['city_grant_requests', 'nation_id', 'city_grant_requests_nation_id_foreign']
        );

        if (! $constraint) {
            Schema::table('city_grant_requests', function (Blueprint $table) {
                $table->foreign('nation_id')->references('id')->on('nations')->noActionOnDelete();
            });
        }
    }
};
