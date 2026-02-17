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
        Schema::create('rebuilding_estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cycle_id');
            $table->foreignId('nation_id');
            $table->unsignedInteger('city_count');
            $table->foreignId('tier_id');
            $table->decimal('target_infrastructure', 8, 2);
            $table->decimal('estimated_amount', 18, 2)->default(0);
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['cycle_id', 'nation_id']);
            $table->index('cycle_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rebuilding_estimates');
    }
};
