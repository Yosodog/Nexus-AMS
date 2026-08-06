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
        Schema::table('milcom_events', function (Blueprint $table): void {
            $table->string('deduplication_key', 64)->nullable()->after('event_type');
            $table->timestamp('dismissed_at')->nullable()->after('occurred_at');
            $table->foreignId('dismissed_by_user_id')->nullable()->after('dismissed_at')
                ->constrained('users')->nullOnDelete();
            $table->unique('deduplication_key', 'milcom_event_dedupe_unique');
            $table->index(
                ['event_type', 'dismissed_at', 'id'],
                'milcom_event_open_alert_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('milcom_events', function (Blueprint $table): void {
            $table->dropIndex('milcom_event_open_alert_idx');
            $table->dropUnique('milcom_event_dedupe_unique');
            $table->dropConstrainedForeignId('dismissed_by_user_id');
            $table->dropColumn(['deduplication_key', 'dismissed_at']);
        });
    }
};
