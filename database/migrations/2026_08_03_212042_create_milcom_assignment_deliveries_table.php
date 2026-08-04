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
        Schema::create('milcom_assignment_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id');
            $table->foreignId('assignment_id');
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending');
            $table->string('dedupe_key')->unique();
            $table->string('subject');
            $table->json('payload_snapshot');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('operation_id', 'milcom_delivery_operation_fk')
                ->references('id')->on('milcom_operations')->cascadeOnDelete();
            $table->foreign('assignment_id', 'milcom_delivery_assignment_fk')
                ->references('id')->on('milcom_assignments')->cascadeOnDelete();
            $table->unique(['assignment_id', 'channel'], 'milcom_delivery_assignment_channel_unique');
            $table->index(['operation_id', 'channel', 'status', 'id'], 'milcom_delivery_operation_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milcom_assignment_deliveries');
    }
};
