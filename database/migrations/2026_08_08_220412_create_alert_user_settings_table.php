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
        Schema::create('alert_user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->time('default_digest_time')->default('09:00:00');
            $table->unsignedTinyInteger('default_digest_weekday')->default(1);
            $table->boolean('discord_enabled')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_user_settings');
    }
};
