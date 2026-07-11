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
        Schema::create('blockade_relief_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_nation_id')->constrained('nations')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('war_id');
            $table->foreignId('blockading_nation_id')->constrained('nations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('claimed_by_nation_id')->nullable()->constrained('nations')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable()->default(1);
            $table->string('note', 255)->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('resolution_reason', 100)->nullable();
            $table->timestamps();

            $table->unique(
                ['requester_nation_id', 'war_id', 'pending_key'],
                'blockade_relief_request_pending_unique'
            );
            $table->index(['status', 'deadline_at'], 'blockade_relief_reconcile_index');
            $table->index('war_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blockade_relief_requests');
    }
};
