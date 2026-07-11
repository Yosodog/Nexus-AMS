<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_command_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('interaction_id', 32)->unique();
            $table->string('guild_id', 32);
            $table->string('discord_user_id', 32);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('route');
            $table->string('request_hash', 64);
            $table->string('status', 24)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['discord_user_id', 'created_at']);
        });

        Schema::create('discord_action_intents', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discord_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guild_id', 32);
            $table->string('action', 80);
            $table->json('payload');
            $table->string('status', 24)->default('draft');
            $table->string('created_interaction_id', 32)->nullable();
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action', 'status']);
            $table->index(['expires_at', 'status']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('discord_action_intent_id')
                ->nullable()
                ->after('id')
                ->constrained('discord_action_intents')
                ->nullOnDelete();
            $table->unique('discord_action_intent_id');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('pending_key');
            $table->index(['status', 'expires_at']);
        });

        DB::table('deposit_requests')
            ->where('status', 'pending')
            ->orderBy('id')
            ->eachById(function (object $request): void {
                DB::table('deposit_requests')
                    ->where('id', $request->id)
                    ->update([
                        'expires_at' => Carbon::parse($request->created_at)->addMinutes(60),
                    ]);
            }, 500, 'id', 'id');
    }

    public function down(): void
    {
        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['discord_action_intent_id']);
            $table->dropConstrainedForeignId('discord_action_intent_id');
        });

        Schema::dropIfExists('discord_action_intents');
        Schema::dropIfExists('discord_command_receipts');
    }
};
