<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_messages')
            ->select(['application_id', 'discord_message_id'])
            ->groupBy('application_id', 'discord_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('application_id')
            ->orderBy('discord_message_id')
            ->get()
            ->each(function (object $duplicate): void {
                $ids = DB::table('application_messages')
                    ->where('application_id', $duplicate->application_id)
                    ->where('discord_message_id', $duplicate->discord_message_id)
                    ->orderBy('id')
                    ->pluck('id');

                DB::table('application_messages')
                    ->whereIn('id', $ids->slice(1)->all())
                    ->delete();
            });

        DB::table('intel_reports')
            ->select('hash')
            ->groupBy('hash')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('hash')
            ->get()
            ->each(function (object $duplicate): void {
                $ids = DB::table('intel_reports')
                    ->where('hash', $duplicate->hash)
                    ->orderBy('id')
                    ->pluck('id');

                DB::table('intel_reports')
                    ->whereIn('id', $ids->slice(1)->all())
                    ->delete();
            });

        Schema::table('application_messages', function (Blueprint $table) {
            $table->unique(['application_id', 'discord_message_id']);
        });

        Schema::table('intel_reports', function (Blueprint $table) {
            $table->unique('hash');
        });
    }

    public function down(): void
    {
        Schema::table('application_messages', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'discord_message_id']);
        });

        Schema::table('intel_reports', function (Blueprint $table) {
            $table->dropUnique(['hash']);
        });
    }
};
