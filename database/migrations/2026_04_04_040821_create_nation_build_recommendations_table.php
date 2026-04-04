<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_build_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id')->unique();
            $table->unsignedBigInteger('alliance_id')->nullable()->index();
            $table->unsignedBigInteger('radiation_snapshot_id')->nullable()->index();
            $table->json('recommended_build_json');
            $table->unsignedSmallInteger('infra_needed')->default(0);
            $table->decimal('land_used', 10, 2)->default(0);
            $table->unsignedTinyInteger('imp_total')->default(0);
            $table->decimal('converted_profit_per_day', 14, 2)->default(0);
            $table->decimal('money_profit_per_day', 14, 2)->default(0);
            $table->json('resource_profit_per_day');
            $table->decimal('disease', 8, 2)->default(0);
            $table->unsignedSmallInteger('pollution')->default(0);
            $table->decimal('crime', 8, 2)->default(0);
            $table->unsignedSmallInteger('commerce')->default(0);
            $table->unsignedInteger('population')->default(0);
            $table->string('price_basis')->default('24h average trade prices');
            $table->timestamp('calculated_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_build_recommendations');
    }
};
