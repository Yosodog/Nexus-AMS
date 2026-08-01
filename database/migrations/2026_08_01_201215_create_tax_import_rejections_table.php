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
        Schema::create('tax_import_rejections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('alliance_id');
            $table->unsignedBigInteger('tax_record_id');
            $table->string('reason');
            $table->text('raw_timestamp')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['alliance_id', 'tax_record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_import_rejections');
    }
};
