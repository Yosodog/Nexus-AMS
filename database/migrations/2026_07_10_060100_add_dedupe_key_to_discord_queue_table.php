<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_queue', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->unique()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('discord_queue', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
