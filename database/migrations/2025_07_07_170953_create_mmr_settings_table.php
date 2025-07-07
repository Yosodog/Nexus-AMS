<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('mmr_settings', function (Blueprint $table) {
            $table->id();
            $table->string('resource')->unique();
            $table->boolean('enabled')->default(true);
            $table->decimal('surcharge_pct', 5, 2)->default(0.00);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('mmr_settings');
    }
};
