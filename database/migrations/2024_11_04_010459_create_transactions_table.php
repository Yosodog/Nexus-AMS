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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Foreign keys for source and destination accounts/nation
            $table->unsignedBigInteger('from_account_id')->nullable();
            $table->unsignedBigInteger('to_account_id')->nullable();
            $table->unsignedBigInteger('nation_id')->nullable();

            // Transaction type: "deposit", "withdrawal", "transfer"
            $table->enum('transaction_type', ['deposit', 'withdrawal', 'transfer']);

            // Resource values involved in the transaction
            $table->decimal('money', 15, 2)->default(0);
            $table->decimal('coal', 14, 2)->default(0);
            $table->decimal('oil', 14, 2)->default(0);
            $table->decimal('uranium', 14, 2)->default(0);
            $table->decimal('iron', 14, 2)->default(0);
            $table->decimal('bauxite', 14, 2)->default(0);
            $table->decimal('lead', 14, 2)->default(0);
            $table->decimal('gasoline', 14, 2)->default(0);
            $table->decimal('munitions', 14, 2)->default(0);
            $table->decimal('steel', 14, 2)->default(0);
            $table->decimal('aluminum', 14, 2)->default(0);
            $table->decimal('food', 14, 2)->default(0);

            // Timestamps for the transaction
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('from_account_id')->references('id')->on('accounts')->noActionOnDelete();
            $table->foreign('to_account_id')->references('id')->on('accounts')->noActionOnDelete();
            $table->foreign('nation_id')->references('id')->on('nations')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
