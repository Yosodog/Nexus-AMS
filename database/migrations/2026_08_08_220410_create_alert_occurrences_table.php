<?php

use App\Support\Database\WorldReference;
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
        Schema::create('alert_occurrences', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 96);
            $table->unsignedSmallInteger('schema_version')->default(1);
            WorldReference::alliance($table)->nullable()->nullOnDeleteInStandalone();
            $table->foreignId('audience_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('source_type', 64);
            $table->string('source_id', 191);
            $table->string('source_version', 64)->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('deep_link_path', 255)->nullable();
            $table->string('severity', 16)->default('normal');
            $table->string('sensitivity', 16)->default('member');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('stale_at')->nullable();
            $table->string('correlation_key', 191)->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->boolean('is_test')->default(false);
            $table->timestamps();

            $table->index(['event_key', 'occurred_at']);
            $table->index(['alliance_id', 'event_key', 'occurred_at'], 'alert_occurrence_scope_event_idx');
            $table->index(['audience_user_id', 'occurred_at'], 'alert_occurrence_user_idx');
            $table->index(['stale_at', 'id']);
            $table->index(['correlation_key', 'occurred_at'], 'alert_occurrence_correlation_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_occurrences');
    }
};
