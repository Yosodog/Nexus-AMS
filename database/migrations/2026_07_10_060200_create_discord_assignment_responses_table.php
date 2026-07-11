<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_assignment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('nation_id')->index();
            $table->string('assignment_type', 32);
            $table->unsignedBigInteger('assignment_id');
            $table->string('response', 32);
            $table->string('reason', 500)->nullable();
            $table->string('discord_interaction_id', 100)->nullable();
            $table->timestamps();

            $table->unique(['assignment_type', 'assignment_id', 'user_id'], 'discord_assignment_response_unique');
            $table->index(['assignment_type', 'assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_assignment_responses');
    }
};
