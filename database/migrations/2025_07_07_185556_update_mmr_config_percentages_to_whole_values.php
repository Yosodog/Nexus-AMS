<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mmr_configs', function (Blueprint $table) {
            foreach ([
                'coal_pct',
                'oil_pct',
                'uranium_pct',
                'iron_pct',
                'bauxite_pct',
                'lead_pct',
                'gasoline_pct',
                'munitions_pct',
                'steel_pct',
                'aluminum_pct',
                'food_pct',
            ] as $col) {
                $table->decimal($col, 5, 2)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mmr_configs', function (Blueprint $table) {
            foreach ([
                'coal_pct',
                'oil_pct',
                'uranium_pct',
                'iron_pct',
                'bauxite_pct',
                'lead_pct',
                'gasoline_pct',
                'munitions_pct',
                'steel_pct',
                'aluminum_pct',
                'food_pct',
            ] as $col) {
                $table->decimal($col, 5, 4)->default(0)->change();
            }
        });
    }
};
