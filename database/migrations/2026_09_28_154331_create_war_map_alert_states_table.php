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
        Schema::create('war_map_alert_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('nation_id');
            $table->unsignedBigInteger('war_id');
            $table->boolean('armed')->default(true);
            $table->unsignedInteger('notification_sequence')->default(0);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamp('last_enqueued_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'nation_id', 'war_id'], 'war_map_alert_recipient_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_map_alert_states');
    }
};
