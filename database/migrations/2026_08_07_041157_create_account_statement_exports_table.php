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
        Schema::create('account_statement_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->index();
            $table->char('request_fingerprint', 64);
            $table->unsignedTinyInteger('active_key')->nullable();
            $table->json('filters');
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['user_id', 'account_id', 'request_fingerprint', 'active_key'],
                'account_statement_exports_active_unique'
            );
            $table->index(
                ['user_id', 'account_id', 'created_at'],
                'account_statement_exports_owner_history_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_statement_exports');
    }
};
