<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mmr_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('city_count'); // 0 = fallback

            // Resources
            $table->unsignedBigInteger('steel')->default(0);
            $table->unsignedBigInteger('aluminum')->default(0);
            $table->unsignedBigInteger('munitions')->default(0);
            $table->unsignedBigInteger('uranium')->default(0);
            $table->unsignedBigInteger('food')->default(0);

            // Unit-producing buildings (per city)
            $table->unsignedTinyInteger('barracks')->default(0);
            $table->unsignedTinyInteger('factories')->default(0);
            $table->unsignedTinyInteger('hangars')->default(0);
            $table->unsignedTinyInteger('drydocks')->default(0);
            $table->unsignedTinyInteger('missiles')->default(0);

            // Fixed unit counts
            $table->unsignedSmallInteger('nukes')->default(0);
            $table->unsignedTinyInteger('spies')->default(0);

            $table->timestamps();
            $table->unique(['city_count']); // No alliance_id needed
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mmr_tiers');
    }
};
