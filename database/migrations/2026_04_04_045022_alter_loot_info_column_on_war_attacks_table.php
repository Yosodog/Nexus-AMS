<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_attacks', function (Blueprint $table) {
            $table->text('loot_info')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('war_attacks', function (Blueprint $table) {
            $table->string('loot_info')->nullable()->change();
        });
    }
};
