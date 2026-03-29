<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_profitability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->unsignedBigInteger('alliance_id')->nullable()->index();
            $table->unsignedBigInteger('radiation_snapshot_id')->nullable()->index();
            $table->string('leader_name');
            $table->string('nation_name');
            $table->unsignedSmallInteger('cities')->default(0);
            $table->decimal('converted_profit_per_day', 14, 2)->default(0);
            $table->decimal('money_profit_per_day', 14, 2)->default(0);
            $table->decimal('city_income_per_day', 14, 2)->default(0);
            $table->decimal('power_cost_per_day', 14, 2)->default(0);
            $table->decimal('food_cost_per_day', 14, 2)->default(0);
            $table->decimal('military_upkeep_per_day', 14, 2)->default(0);
            $table->json('resource_profit_per_day');
            $table->string('price_basis')->default('24h average trade prices');
            $table->timestamp('calculated_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_profitability_snapshots');
    }
};
