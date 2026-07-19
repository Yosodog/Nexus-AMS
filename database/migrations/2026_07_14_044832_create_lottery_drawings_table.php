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
        Schema::create('lottery_drawings', function (Blueprint $table) {
            $table->id();
            $table->dateTime('starts_at')->unique();
            $table->dateTime('ends_at')->index();
            $table->string('status', 20)->default('open')->index();
            $table->boolean('sales_enabled')->default(true);
            $table->decimal('ticket_price', 15, 2);
            $table->unsignedSmallInteger('jackpot_basis_points')->default(9000);
            $table->decimal('jackpot_contribution_per_ticket', 15, 2)->default(45000);
            $table->unsignedSmallInteger('max_tickets_per_purchase')->default(100);
            $table->unsignedInteger('max_tickets_per_nation')->default(10000);
            $table->unsignedInteger('ticket_count')->default(0);
            $table->char('allocation_seed', 64);
            $table->unsignedInteger('next_ticket_sequence')->default(0);
            $table->decimal('rollover_amount', 15, 2)->default(0);
            $table->decimal('jackpot_amount', 15, 2)->default(0);
            $table->char('winning_code', 3)->nullable();
            $table->dateTime('drawn_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lottery_drawings');
    }
};
