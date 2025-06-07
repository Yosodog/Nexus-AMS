<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mmr_tiers', function (Blueprint $table) {
            $table->unsignedBigInteger('money')->default(0)->after('food');
            $table->unsignedBigInteger('gasoline')->default(0)->after('money');
        });
    }

    public function down(): void
    {
        Schema::table('mmr_tiers', function (Blueprint $table) {
            $table->dropColumn(['money', 'gasoline']);
        });
    }
};
