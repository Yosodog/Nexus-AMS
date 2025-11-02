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
        Schema::table('war_counters', function (Blueprint $table) {
            $table->string('war_declaration_type', 20)
                ->default('ordinary')
                ->after('team_size');
        });

        Schema::table('war_plan_targets', function (Blueprint $table) {
            $table->string('preferred_war_type', 20)
                ->default('ordinary')
                ->after('nation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_plan_targets', function (Blueprint $table) {
            $table->dropColumn('preferred_war_type');
        });

        Schema::table('war_counters', function (Blueprint $table) {
            $table->dropColumn('war_declaration_type');
        });
    }
};
