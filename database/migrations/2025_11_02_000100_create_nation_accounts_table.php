<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('nation_id')->primary();
            $table->unsignedInteger('credits')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->string('discord_id', 32)->nullable();
            $table->timestamps();

            $table->foreign('nation_id')
                ->references('id')
                ->on('nations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_accounts');
    }
};
