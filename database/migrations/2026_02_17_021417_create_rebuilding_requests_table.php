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
        Schema::create('rebuilding_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cycle_id');
            $table->foreignId('nation_id');
            $table->foreignId('account_id');
            $table->foreignId('tier_id');
            $table->unsignedInteger('city_count_snapshot');
            $table->decimal('target_infrastructure_snapshot', 8, 2);
            $table->decimal('estimated_amount', 18, 2)->default(0);
            $table->decimal('approved_amount', 18, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'denied', 'expired'])->default('pending');
            $table->string('note')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->foreignId('denied_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index('cycle_id');
            $table->index('nation_id');
            $table->index('account_id');
            $table->index('status');
            $table->index(['cycle_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rebuilding_requests');
    }
};
