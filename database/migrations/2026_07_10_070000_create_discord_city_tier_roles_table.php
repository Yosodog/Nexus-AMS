<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_city_tier_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('bucket_start');
            $table->unsignedSmallInteger('bucket_end');
            $table->string('discord_role_id')->nullable()->unique();
            $table->uuid('last_synced_queue_id')->nullable();
            $table->timestamps();

            $table->unique(['bucket_start', 'bucket_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_city_tier_roles');
    }
};
