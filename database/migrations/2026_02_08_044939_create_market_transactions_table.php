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
        Schema::create('market_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('resource');
            $table->decimal('amount', 15, 2);
            $table->decimal('adjustment_percent', 6, 2);
            $table->decimal('final_price', 12, 4);
            $table->decimal('money_paid', 14, 2);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['resource', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_transactions');
    }
};
