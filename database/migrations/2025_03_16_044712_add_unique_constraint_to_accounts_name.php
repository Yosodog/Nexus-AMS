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
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('unique_name_key')->nullable();
            });

            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('unique_name_key')->virtualAs(
                "IF(deleted_at IS NULL, CONCAT(name, '_', nation_id), NULL)"
            )->nullable();
            $table->unique('unique_name_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('unique_name_key');
            });

            return;
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['unique_name_key']);
            $table->dropColumn('unique_name_key');
        });
    }
};
