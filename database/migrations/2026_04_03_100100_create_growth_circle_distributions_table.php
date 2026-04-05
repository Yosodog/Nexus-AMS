<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_circle_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nation_id');
            $table->float('food_sent')->default(0);
            $table->float('uranium_sent')->default(0);
            $table->float('food_level_before')->default(0);
            $table->float('uranium_level_before')->default(0);
            $table->unsignedInteger('city_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('nation_id')->references('id')->on('nations')->cascadeOnDelete();
            $table->index(['nation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_circle_distributions');
    }
};
