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
        Schema::create('war_counter_reimbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('war_counter_id')
                ->constrained('war_counters')
                ->cascadeOnDelete();
            $table->foreignId('nation_id')
                ->constrained('nations')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->foreignId('manual_transaction_id')
                ->nullable()
                ->constrained('manual_transactions')
                ->nullOnDelete();
            $table->foreignId('reimbursed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->decimal('gasoline', 14, 2)->default(0);
            $table->decimal('munitions', 14, 2)->default(0);
            $table->decimal('steel', 14, 2)->default(0);
            $table->decimal('aluminum', 14, 2)->default(0);
            $table->decimal('resources_cost', 14, 2)->default(0);
            $table->decimal('unit_loss_cost', 14, 2)->default(0);
            $table->decimal('infra_loss_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['war_counter_id', 'nation_id'], 'war_counter_reimbursements_counter_nation_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_counter_reimbursements');
    }
};
