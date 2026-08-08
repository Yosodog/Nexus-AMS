<?php

use App\Support\Database\WorldReference;
use App\Support\Database\WorldSchema;
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
        WorldSchema::table('nations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('ground_capacity_research')->nullable();
            $table->unsignedTinyInteger('ground_cost_research')->nullable();
            $table->unsignedTinyInteger('air_capacity_research')->nullable();
            $table->unsignedTinyInteger('air_cost_research')->nullable();
            $table->unsignedTinyInteger('naval_capacity_research')->nullable();
            $table->unsignedTinyInteger('naval_cost_research')->nullable();
            $table->decimal('treasure_income_modifier', 8, 6)->nullable();
            $table->integer('color_turn_bonus')->nullable();
            $table->timestamp('economy_context_synced_at')->nullable();
        });

        Schema::table('nation_profitability_snapshots', function (Blueprint $table): void {
            $table->unsignedSmallInteger('model_version')->default(1)->index();
            WorldReference::marketPriceSnapshot($table)
                ->nullable()
                ->restrictOnDeleteInStandalone();
            $table->json('calculation_context')->nullable();
        });

        Schema::table('nation_build_recommendations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('model_version')->default(1)->index();
            WorldReference::marketPriceSnapshot($table)
                ->nullable()
                ->restrictOnDeleteInStandalone();
            $table->json('calculation_context')->nullable();
            $table->unsignedSmallInteger('available_slots')->default(0);
            $table->unsignedSmallInteger('cities_below_target')->default(0);
            $table->decimal('infrastructure_shortfall', 12, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nation_build_recommendations', function (Blueprint $table): void {
            WorldReference::drop($table, 'market_price_snapshot_id');
            $table->dropColumn([
                'model_version',
                'market_price_snapshot_id',
                'calculation_context',
                'available_slots',
                'cities_below_target',
                'infrastructure_shortfall',
            ]);
        });

        Schema::table('nation_profitability_snapshots', function (Blueprint $table): void {
            WorldReference::drop($table, 'market_price_snapshot_id');
            $table->dropColumn(['model_version', 'market_price_snapshot_id', 'calculation_context']);
        });

        WorldSchema::table('nations', function (Blueprint $table): void {
            $table->dropColumn([
                'ground_capacity_research',
                'ground_cost_research',
                'air_capacity_research',
                'air_cost_research',
                'naval_capacity_research',
                'naval_cost_research',
                'treasure_income_modifier',
                'color_turn_bonus',
                'economy_context_synced_at',
            ]);
        });
    }
};
