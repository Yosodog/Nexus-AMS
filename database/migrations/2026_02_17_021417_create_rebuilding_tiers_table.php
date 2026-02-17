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
        Schema::create('rebuilding_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedInteger('min_city_count');
            $table->unsignedInteger('max_city_count')->nullable();
            $table->decimal('target_infrastructure', 8, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('min_city_count');
            $table->index('max_city_count');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rebuilding_tiers');
    }
};
