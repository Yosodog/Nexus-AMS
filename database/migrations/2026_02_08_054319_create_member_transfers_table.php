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
        Schema::create('member_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('from_nation_id')->constrained('nations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('to_nation_id')->constrained('nations')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('canceled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24);
            $table->decimal('money', 15, 2)->default(0);
            $table->decimal('coal', 15, 2)->default(0);
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
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'to_nation_id']);
            $table->index(['status', 'from_nation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_transfers');
    }
};
