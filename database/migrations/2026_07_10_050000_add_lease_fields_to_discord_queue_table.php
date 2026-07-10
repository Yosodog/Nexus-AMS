<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_queue', function (Blueprint $table) {
            $table->uuid('claim_request_id')->nullable()->unique()->after('attempts');
            $table->uuid('worker_id')->nullable()->after('claim_request_id');
            $table->uuid('lease_token')->nullable()->unique()->after('worker_id');
            $table->timestamp('leased_until')->nullable()->index()->after('lease_token');
            $table->json('result')->nullable()->after('leased_until');
            $table->json('last_error')->nullable()->after('result');
            $table->timestamp('completed_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('discord_queue', function (Blueprint $table) {
            $table->dropUnique(['claim_request_id']);
            $table->dropUnique(['lease_token']);
            $table->dropIndex(['leased_until']);
            $table->dropColumn([
                'claim_request_id',
                'worker_id',
                'lease_token',
                'leased_until',
                'result',
                'last_error',
                'completed_at',
            ]);
        });
    }
};
