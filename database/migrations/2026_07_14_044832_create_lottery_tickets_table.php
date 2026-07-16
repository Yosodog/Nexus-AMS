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
        Schema::create('lottery_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lottery_drawing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->char('code', 3);
            $table->decimal('price_paid', 15, 2);
            $table->decimal('jackpot_contribution', 15, 2);
            $table->timestamps();

            $table->unique(['lottery_drawing_id', 'code']);
            $table->index(['lottery_drawing_id', 'nation_id']);
            $table->index(['user_id', 'lottery_drawing_id']);
            $table->index(['account_id', 'lottery_drawing_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lottery_tickets');
    }
};
