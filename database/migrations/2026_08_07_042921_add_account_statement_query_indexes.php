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
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->index(
                ['account_id', 'created_at'],
                'manual_transactions_account_created_at_index'
            );
        });

        Schema::table('growth_circle_distributions', function (Blueprint $table) {
            $table->index(
                ['account_id', 'created_at'],
                'growth_distributions_account_created_at_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_transactions', function (Blueprint $table) {
            $table->dropIndex('manual_transactions_account_created_at_index');
        });

        Schema::table('growth_circle_distributions', function (Blueprint $table) {
            $table->dropIndex('growth_distributions_account_created_at_index');
        });
    }
};
