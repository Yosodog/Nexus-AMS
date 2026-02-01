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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->uuid('request_id')->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('user_agent', 512)->nullable();

            $table->string('actor_type')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->index(['actor_type', 'actor_id']);

            $table->string('category')->index();
            $table->string('action')->index();
            $table->string('outcome')->index();
            $table->string('severity')->index();
            $table->string('message')->nullable();

            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);

            $table->json('context')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
