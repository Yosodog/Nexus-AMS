<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('discord_reconcile_revision')->default(0)->after('discord_channel_id');
            $table->uuid('discord_reconcile_queue_id')->nullable()->after('discord_reconcile_revision')->index();
            $table->char('discord_reconcile_desired_hash', 64)->nullable()->after('discord_reconcile_queue_id');
            $table->json('discord_reconcile_issues')->nullable()->after('discord_reconcile_desired_hash');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['discord_reconcile_queue_id']);
            $table->dropColumn([
                'discord_reconcile_revision',
                'discord_reconcile_queue_id',
                'discord_reconcile_desired_hash',
                'discord_reconcile_issues',
            ]);
        });
    }
};
