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
        Schema::create('offshore_guardrails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offshore_id')->constrained('offshores')->cascadeOnDelete();
            $table->enum('resource', [
                'money',
                'coal',
                'oil',
                'uranium',
                'iron',
                'bauxite',
                'lead',
                'gasoline',
                'munitions',
                'steel',
                'aluminum',
                'food',
                'credits',
            ]);
            $table->decimal('minimum_amount', 20, 2)->default(0);
            $table->timestamps();

            $table->unique(['offshore_id', 'resource']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offshore_guardrails');
    }
};
