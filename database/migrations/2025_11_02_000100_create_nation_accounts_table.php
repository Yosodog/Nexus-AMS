<?php

use App\Support\Database\WorldReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nation_accounts', function (Blueprint $table) {
            WorldReference::nationPrimaryKey($table)->cascadeOnDeleteInStandalone();
            $table->unsignedInteger('credits')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->string('discord_id', 32)->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_accounts');
    }
};
