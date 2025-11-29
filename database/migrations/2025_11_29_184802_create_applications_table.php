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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('nation_id')->index();
            $table->string('leader_name_snapshot');
            $table->string('discord_user_id')->index();
            $table->string('discord_username');
            $table->string('discord_channel_id')->nullable()->index();
            $table->string('status')->default(\App\Enums\ApplicationStatus::Pending->value)->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('approved_by_discord_id')->nullable();
            $table->string('denied_by_discord_id')->nullable();
            $table->string('cancelled_by_discord_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
