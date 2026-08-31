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
        Schema::create('main_bank_credentials', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('api_key')->nullable();
            $table->text('mutation_key')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('main_bank_credentials');
    }
};
