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
        Schema::create('recruitment_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->longText('message');
            $table->timestamps();
        });

        $appName = config('app.name', 'Nexus');

        $defaults = [
            'primary' => '<p>Welcome to Politics &amp; War!</p>'
                ."<p>The team at {$appName} would love to help you get started. "
                .'Join our Discord and we can walk you through your first steps.</p>',
            'follow_up' => '<p>Hey there! Just following up to see how your nation is progressing.</p>'
                ."<p>If you are still looking for an alliance, we'd love to have you at {$appName}.</p>",
        ];

        $existing = DB::table('settings')
            ->whereIn('key', [
                'recruitment_primary_message',
                'recruitment_follow_up_message',
            ])->pluck('value', 'key');

        $now = now();

        foreach ($defaults as $type => $default) {
            $key = $type === 'primary'
                ? 'recruitment_primary_message'
                : 'recruitment_follow_up_message';

            $message = $existing[$key] ?? $default;

            DB::table('recruitment_messages')->updateOrInsert(
                ['type' => $type],
                [
                    'message' => $message,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        DB::table('settings')
            ->whereIn('key', [
                'recruitment_primary_message',
                'recruitment_follow_up_message',
            ])->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $messages = DB::table('recruitment_messages')->get(['type', 'message']);

        foreach ($messages as $record) {
            $key = $record->type === 'primary'
                ? 'recruitment_primary_message'
                : ($record->type === 'follow_up'
                    ? 'recruitment_follow_up_message'
                    : null);

            if ($key === null) {
                continue;
            }

            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => Str::limit($record->message, 255)]
            );
        }

        Schema::dropIfExists('recruitment_messages');
    }
};
