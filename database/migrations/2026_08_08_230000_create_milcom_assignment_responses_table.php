<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milcom_assignment_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')
                ->constrained('milcom_assignments')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->constrained('nations')->restrictOnDelete();
            $table->string('response', 32);
            $table->string('reason', 500)->nullable();
            $table->string('discord_interaction_id', 100)->nullable();
            $table->timestamp('responded_at');
            $table->timestamps();

            $table->unique('assignment_id', 'milcom_assignment_response_assignment_unique');
            $table->index(['user_id', 'nation_id', 'responded_at'], 'milcom_assignment_response_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milcom_assignment_responses');
    }
};
