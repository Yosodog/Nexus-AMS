<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('war_plans', function (Blueprint $table) {
            $table->renameColumn('preferred_nations_per_target', 'preferred_targets_per_nation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_plans', function (Blueprint $table) {
            $table->renameColumn('preferred_targets_per_nation', 'preferred_nations_per_target');
        });
    }
};
