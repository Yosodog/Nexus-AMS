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
        Schema::create('lottery_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('lottery_drawing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('nation_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('total_cost', 15, 2);
            $table->decimal('jackpot_contribution', 15, 2);
            $table->foreignId('manual_transaction_id')
                ->nullable()
                ->unique()
                ->constrained('manual_transactions')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['lottery_drawing_id', 'nation_id']);
        });

        Schema::table('lottery_tickets', function (Blueprint $table) {
            $table->foreignId('lottery_purchase_id')
                ->nullable()
                ->after('lottery_drawing_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lottery_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lottery_purchase_id');
        });

        Schema::dropIfExists('lottery_purchases');
    }
};
