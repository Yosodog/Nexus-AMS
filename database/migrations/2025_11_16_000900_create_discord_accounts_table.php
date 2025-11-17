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
        Schema::create('discord_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('discord_id');
            $table->string('discord_username');
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['discord_id']);
            $table->index(['user_id', 'unlinked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discord_accounts');
    }
};
