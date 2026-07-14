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
        Schema::table('lottery_drawings', function (Blueprint $table) {
            $table->foreignId('winning_ticket_id')
                ->nullable()
                ->after('jackpot_amount')
                ->constrained('lottery_tickets')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lottery_drawings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('winning_ticket_id');
        });
    }
};
