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
        Schema::create('alert_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 96);
            $table->timestamps();

            $table->unique(['alert_subscription_id', 'event_key'], 'alert_subscription_event_unique');
            $table->index(['event_key', 'alert_subscription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_subscription_events');
    }
};
