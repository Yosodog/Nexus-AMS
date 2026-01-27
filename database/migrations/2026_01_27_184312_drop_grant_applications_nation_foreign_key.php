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
            ['grant_applications', 'nation_id', 'grant_applications_nation_id_foreign']
        );

        if ($constraint) {
            Schema::table('grant_applications', function (Blueprint $table) {
                $table->dropForeign('grant_applications_nation_id_foreign');
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
            ['grant_applications', 'nation_id', 'grant_applications_nation_id_foreign']
        );

        if (! $constraint) {
            Schema::table('grant_applications', function (Blueprint $table) {
                $table->foreign('nation_id')->references('id')->on('nations')->noActionOnDelete();
            });
        }
    }
};
