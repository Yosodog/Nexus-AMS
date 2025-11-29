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
        Schema::create('application_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('discord_message_id');
            $table->string('discord_user_id')->index();
            $table->string('discord_username');
            $table->string('discord_channel_id')->index();
            $table->text('content');
            $table->boolean('is_staff')->default(false);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['application_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_messages');
    }
};
