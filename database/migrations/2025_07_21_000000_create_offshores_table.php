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
        Schema::create('offshores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('alliance_id')->constrained('alliances');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->text('api_key')->nullable();
            $table->text('mutation_key')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offshores');
    }
};
