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
        Schema::create('tax_import_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id')->unique();
            $table->unsignedBigInteger('last_scanned_id')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_import_checkpoints');
    }
};
