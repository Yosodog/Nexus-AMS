<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        WorldSchema::table('war_attacks', function (Blueprint $table) {
            $table->text('loot_info')->nullable()->change();
        });
    }

    public function down(): void
    {
        WorldSchema::table('war_attacks', function (Blueprint $table) {
            $table->string('loot_info')->nullable()->change();
        });
    }
};
