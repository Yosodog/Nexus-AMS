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
        Schema::create('offshore_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offshore_id')->constrained('offshores')->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed'])->default('pending');
            $table->json('payload');
            $table->json('response_metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['offshore_id', 'status']);
            $table->index('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offshore_transfers');
    }
};
