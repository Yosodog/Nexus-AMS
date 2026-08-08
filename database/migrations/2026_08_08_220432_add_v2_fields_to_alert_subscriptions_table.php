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
        Schema::table('alert_subscriptions', function (Blueprint $table) {
            $table->string('target_type', 32)->nullable()->after('config');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->json('filter_config')->nullable()->after('target_id');
            $table->string('status', 32)->default('active')->after('is_active');
            $table->string('status_reason', 100)->nullable()->after('status');
            $table->string('delivery_mode', 16)->default('immediate')->after('cooldown_minutes');
            $table->boolean('discord_enabled')->default(true)->after('delivery_mode');
            $table->decimal('rearm_percent', 5, 2)->default(1)->after('discord_enabled');
            $table->string('timezone', 64)->nullable()->after('rearm_percent');
            $table->string('last_source_version', 64)->nullable()->after('last_triggered_at');
            $table->timestamp('last_source_observed_at')->nullable()->after('last_source_version');
            $table->string('active_fingerprint', 64)->nullable()->after('last_source_observed_at');

            $table->index(['status', 'target_type', 'target_id'], 'alert_subscription_target_idx');
            $table->index(['user_id', 'status', 'created_at'], 'alert_subscription_user_status_idx');
            $table->unique(['user_id', 'active_fingerprint'], 'alert_subscription_active_fingerprint_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_subscriptions', function (Blueprint $table) {
            $table->dropUnique('alert_subscription_active_fingerprint_unique');
            $table->dropIndex('alert_subscription_target_idx');
            $table->dropIndex('alert_subscription_user_status_idx');
            $table->dropColumn([
                'target_type',
                'target_id',
                'filter_config',
                'status',
                'status_reason',
                'delivery_mode',
                'discord_enabled',
                'rearm_percent',
                'timezone',
                'last_source_version',
                'last_source_observed_at',
                'active_fingerprint',
            ]);
        });
    }
};
