<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('no_raid_list', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->unique()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_raid_list');
    }
};
