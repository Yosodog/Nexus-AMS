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
        Schema::create('operations_work_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordination_id');
            $table->foreignId('user_id');
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->foreign('coordination_id', 'operations_watch_coordination_fk')
                ->references('id')->on('operations_work_coordination')->cascadeOnDelete();
            $table->foreign('user_id', 'operations_watch_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['coordination_id', 'user_id'], 'operations_watch_coord_user_unique');
            $table->index(['user_id', 'last_notified_at'], 'operations_watch_user_notify_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations_work_watches');
    }
};
