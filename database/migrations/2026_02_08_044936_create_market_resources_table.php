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
        Schema::create('market_resources', function (Blueprint $table) {
            $table->id();
            $table->string('resource')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('adjustment_percent', 6, 2)->default(0);
            $table->decimal('buy_cap_remaining', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_resources');
    }
};
