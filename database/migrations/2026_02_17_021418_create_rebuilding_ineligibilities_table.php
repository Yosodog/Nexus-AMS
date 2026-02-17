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
        Schema::create('rebuilding_ineligibilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cycle_id');
            $table->foreignId('nation_id');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable();
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
        Schema::dropIfExists('rebuilding_ineligibilities');
    }
};
