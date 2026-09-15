<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('recruitment_messages')) {
            if (! Schema::hasColumn('recruitment_messages', 'name')) {
                Schema::table('recruitment_messages', function (Blueprint $table) {
                    // Drop the unique constraint on type to allow multiple variant messages.
                    $table->dropUnique(['type']);

                    $table->string('name', 100)->nullable()->after('id');
                    $table->string('subject', 50)->nullable()->after('name');
                    $table->string('tracking_key', 32)->nullable()->unique()->after('message');
                    $table->boolean('is_active')->default(true)->after('tracking_key');
                    $table->unsignedInteger('lifetime_sends')->default(0)->after('is_active');
                    $table->unsignedInteger('lifetime_clicks')->default(0)->after('lifetime_sends');
                    $table->unsignedInteger('current_sends')->default(0)->after('lifetime_clicks');
                    $table->unsignedInteger('current_clicks')->default(0)->after('current_sends');
                    $table->softDeletes();
                });
            }

            // Backfill existing rows with initial values if not yet set.
            $primarySubject = DB::table('settings')
                ->where('key', 'recruitment_primary_subject')
                ->value('value') ?: config('app.name').' Recruitment';

            $followUpSubject = DB::table('settings')
                ->where('key', 'recruitment_follow_up_subject')
                ->value('value') ?: 'Checking in from '.config('app.name');

            DB::table('recruitment_messages')
                ->where('type', 'primary')
                ->whereNull('name')
                ->update([
                    'name' => 'Default Recruitment Pitch',
                    'subject' => mb_substr(trim($primarySubject), 0, 50),
                    'tracking_key' => Str::lower(Str::random(10)),
                    'is_active' => true,
                ]);

            DB::table('recruitment_messages')
                ->where('type', 'follow_up')
                ->whereNull('name')
                ->update([
                    'name' => 'Follow-up Message',
                    'subject' => mb_substr(trim($followUpSubject), 0, 50),
                    'is_active' => false,
                ]);
        }

        if (Schema::hasTable('recruited_nations') && ! Schema::hasColumn('recruited_nations', 'recruitment_message_id')) {
            Schema::table('recruited_nations', function (Blueprint $table) {
                $table->foreignId('recruitment_message_id')
                    ->nullable()
                    ->after('nation_id')
                    ->constrained('recruitment_messages')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('recruitment_message_clicks')) {
            Schema::drop('recruitment_message_clicks');
        }

        Schema::create('recruitment_message_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_message_id')
                ->constrained('recruitment_messages')
                ->cascadeOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recruitment_message_id', 'created_at'], 'rm_clicks_message_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_message_clicks');

        if (Schema::hasTable('recruited_nations') && Schema::hasColumn('recruited_nations', 'recruitment_message_id')) {
            Schema::table('recruited_nations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('recruitment_message_id');
            });
        }

        if (Schema::hasTable('recruitment_messages') && Schema::hasColumn('recruitment_messages', 'name')) {
            Schema::table('recruitment_messages', function (Blueprint $table) {
                $table->dropColumn([
                    'name',
                    'subject',
                    'tracking_key',
                    'is_active',
                    'lifetime_sends',
                    'lifetime_clicks',
                    'current_sends',
                    'current_clicks',
                    'deleted_at',
                ]);

                $table->unique('type');
            });
        }
    }
};
