<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alliances', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('acronym', 10);
            $table->float('score')->index();
            $table->string('color', 20);
            $table->float('average_score');
            $table->boolean('accept_members')->default(true);
            $table->string('flag')->nullable();
            $table->string('forum_link')->nullable();
            $table->string('discord_link')->nullable();
            $table->string('wiki_link')->nullable();
            $table->unsignedSmallInteger('rank')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alliances');
    }
};
