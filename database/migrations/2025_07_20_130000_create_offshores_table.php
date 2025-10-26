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
            $table->foreignId('alliance_id')->nullable()->constrained('alliances')->nullOnDelete();
            $table->unsignedInteger('priority')->default(1);
            $table->boolean('enabled')->default(true);
            $table->decimal('min_money', 18, 2)->default(0);
            $table->json('min_resources')->nullable();
            $table->text('api_key')->nullable();
            $table->text('api_mutation_key')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['priority']);
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
